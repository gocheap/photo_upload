<!DOCTYPE html>

<?php

################################################################################################################################################
# RAFAEL TENA (A20314078)
# From: Bakery - Jeremy Hajek
# Notes:
# - Read and understood all the code
# - Changed Queue of SQS to mine, I precreated it
# - Changed the domain of the select statement in SDB to mine
# - Included code for putting the new watermarked photo in the bucket
# - Included code for updating simpleDB with finished url
# - Updated to use sessions
# - Included a sleep to let the instance download the image in the bucket
# - Included "WaitTimeSeconds" to the receivemessage in the queue to give it time to retrieve it, as sometimes the code failed because of this
# - Changed the function to save the watermarked image with another name
# - Added VisibilityTimeout to queue to be certain that nobody reads the same message while its being read/consumed in cleanup.php
#################################################################################################################################################

#retrieve these values that were set in process.php to make our code more flexible

session_start();
$queueURL = $_SESSION['queueurl'];
$domain = $_SESSION['domain'];

// Include the SDK using the Composer autoloader
require 'vendor/autoload.php';

use Aws\SimpleDb\SimpleDbClient;
use Aws\S3\S3Client;
use Aws\Sqs\SqsClient;
use Aws\Common\Aws;
use Aws\SimpleDb\Exception\InvalidQueryExpressionException;

//aws factory
$aws = Aws::factory('/var/www/vendor/aws/aws-sdk-php/src/Aws/Common/Resources/custom-config.php');

// Instantiate the S3 client with your AWS credentials and desired AWS region
$client = $aws->get('S3');

$sdbclient = $aws->get('SimpleDb');

$sqsclient = $aws->get('Sqs');

$mbody="";

$receipthandle="";

##############################################################################
# SQS Read the queue for some information -- we will consume the queue later
# WaitTimeSeconds is to give some time to process it. VisibilityTimeout is to
# give a whole minute that the window of the message will be open and nobody
# else will open it
##############################################################################

// Aded WaitTimeSeconds param
$result = $sqsclient->receiveMessage(array(
    // QueueUrl is required
    'QueueUrl' => $queueURL,
    'MaxNumberOfMessages' => 1,
    'WaitTimeSeconds' => 20,
    'VisibilityTimeout' => 60,
));

######################################
# save the receipthandle value
######################################

foreach ($result->getPath('Messages/*/ReceiptHandle') as $r) {
    // Do something with the message
    $receipthandle=$r;
}

####################################################
# Probably need some logic in here to handle delays)
####################################################

foreach ($result->getPath('Messages/*/Body') as $messageBody) {
    // Do something with the message
    $mbody=$messageBody;
}

#################################################################
# Select from SimpleDB element where id = the id in the Queue
#################################################################

$exp = "select * from $domain where id = '$mbody'";
//echo "\n".$exp."\n";

try {
$iterator = $sdbclient->getIterator('Select', array(
    'SelectExpression' => $exp,
));
} catch(InvalidQueryExpression $i) {
 echo 'Invalid query: '. $i->getMessage() . "\n";
}


####################################################################
# Declare some variables as place holders for the select object
####################################################################

$email = '';
$rawurl = '';
$finishedurl = '';
$bucket = '';
$id = '';
$phone = '';
$filename = '';
$localfilename = ""; // this is a local variable used to store the content of the s3 object


$item_name = ""; // this is for using the SimpleDb after


###################################################################
# Now we are going to loop through the response object to get the 
# values of the returned object
##################################################################

foreach ($iterator as $item) {

	$item_name = $item['Name'];

     	foreach ($item['Attributes'] as $attribute) {
		switch ($attribute['Name']) {
		    case "id": 
			$id = $attribute['Value'];
			break;
		    case "email":
			$email = $attribute['Value']; 
			break;
		    case "bucket":
			$bucket = $attribute['Value'];
			break;
		   case "rawurl":
			$rawurl = $attribute['Value'];
			break;
		   case "finishedurl":
		
			break;
		   case "filename":
			$filename = $attribute['Value'];
			break;
		   case "phone":
			$phone = $attribute['Value'];
			break;
		   default: 

		} // end of switch 
  	} // end of inner for loop 
}//end of outer for loop



###########################################################################
#  Now that you have the URI returned in the S3 object you can use wget -
# http://en.wikipedia.org/wiki/Wget to pull down the image from the S3 url
# then we add the stamp on the picture save the image out and then reupload
# it to S3 and then update the item in SimpleDb  S3 has a prefix URL which can
# be hard coded https://s3.amazonaws.com
############################################################################

//I will save the watermarket image with a different name
$localfilename = "/tmp/" . $filename;
$newfilename = pathinfo($filename, PATHINFO_FILENAME)."_watermark.png";
$newlocalfilename = "/tmp/" . $newfilename;
$result = $client->getObject(array(
    'Bucket' => $bucket,
    'Key'    => $filename,
    'SaveAs' => $localfilename,
));

// This sleep is to give time to the S3 to download the image in the instance. I don't know why it
// doesn't work without that, but it took me several hours to discover this problem.
sleep(2);

############################################################################
#  Now that we have called the s3 object and downloaded (getObject) the file
# to our local system - lets pass the file to our watermark library 
# http://en.wikipedia.org/wiki/Watermark -- using a function  
############################################################################

addStamp($localfilename, $newlocalfilename);

#########################################################################
# Upload the newly rendered image back to the S3 bucket the original came from
#########################################################################

// Upload an object by streaming the contents of a file
// $pathToFile should be absolute path to a file on disk
// I use the code of process.php

$result = $client->putObject(array(
    'ACL'        => 'private',
    'Bucket'     => $bucket,
    'Key'        => $newfilename,
    'SourceFile' => $newlocalfilename,
    'Metadata'   => array(
        'timestamp' => time(),
        'md5' =>  md5_file($newlocalfilename),
    )
));

$newurl= $result['ObjectURL'];

#################################################################################
# Update the SimpleDB object giving the URI of the S3 object to the 'finishedurl' 
# Attribute Value Pair in Simple DB
#################################################################################

$result = $sdbclient->putAttributes(array(
    	// DomainName is required
    	'DomainName' => $domain,
   	// ItemName is required
    	'ItemName' =>$item_name ,
    	// Attributes is required
    	'Attributes' => array(
		 array(
		    'Name' => 'finishedurl',
		    'Value' => $newurl,
		),     
    ),
));


###########################################################################
# PHP function for adding a "stamp" or watermark through the php gd library
###########################################################################

function addStamp($image, $destination)
{

// Load the stamp and the photo to apply the watermark to
// http://php.net/manual/en/function.imagecreatefromgif.php
$stamp = imagecreatefromgif('happy_trans.gif');
$im = imagecreatefromjpeg($image);

// Set the margins for the stamp and get the height/width of the stamp image
$marge_right = 10;
$marge_bottom = 10;
$sx = imagesx($stamp);
$sy = imagesy($stamp);

// Copy the stamp image onto our photo using the margin offsets and the photo 
// width to calculate positioning of the stamp. 
imagecopy($im, $stamp, imagesx($im) - $sx - $marge_right, imagesy($im) - $sy - $marge_bottom, 0, 0, imagesx($stamp), imagesy($stamp));

// Output and free memory
// header('Content-type: image/png');
imagepng($im, $destination);
imagedestroy($im);

} // end of function

$_SESSION['newurl'] = $newurl;
$_SESSION['rawurl'] = $rawurl;
$_SESSION['bucket'] = $bucket;
$_SESSION['newfilename'] = $newfilename;
$_SESSION['receipthandle'] = $receipthandle;

?>
<html>
<head>
	<title>Resize PHP</title>
	<link href="bootstrap.css" rel="stylesheet" type="text/css">
</head>
<body>
	<div class="container-narrow">
		<fieldset id="customfieldset"><legend class="text-center" id="customlegend">Thank you again!</legend>
		<h4 class="text-center">Please wait... you will be redirected again soon!!</h4>
		</fieldset>
	</div>
<script>
window.location = 'cleanup.php';
</script>
</body>
</html>
