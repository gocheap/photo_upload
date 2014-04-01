<!DOCTYPE html>
<?php
######################################################################################
# RAFAEL TENA (A20314078)
# From: Bakery - Jeremy Hajek
# Notes:
# - Read and understood all the code
# - Changed Domain name of SDB to mine
# - Changed Queue of SQS to mine
# - Updated to use sessions
# - added wait message at the end
# - added code to create unique topics for each user
# - added code to check if a user was already in a topic. In that case, use that topic
# - Added code to check if the PHONE or EMAIL are already subscribed to the service
#   in that case, no need to send a confirmation required msg SMS or EMAIL
######################################################################################
session_start();
// Include the SDK using the Composer autoloader
require 'vendor/autoload.php';

use Aws\Sns\SnsClient;
use Aws\Sns\Exception\InvalidParameterException;
use Aws\Common\Aws;
use Aws\Sqs\SqsClient;
use Aws\SimpleDb\SimpleDbClient;

// Instantiate the S3 client with your AWS credentials and desired AWS regionws\Common\Aws;

//aws factory
$aws = Aws::factory('/var/www/vendor/aws/aws-sdk-php/src/Aws/Common/Resources/custom-config.php');

$client = $aws->get('S3'); 

$sdbclient = $aws->get('SimpleDb'); 

$snsclient = $aws->get('Sns'); 

$sqsclient = $aws->get('Sqs');

###################################################
#  All the variables needed
###################################################

$UUID = uniqid();
$email_original = $_POST["email"];
$email = str_replace("@","-",$_POST["email"]); 
$bucket = str_replace("@", "-",$_POST["email"]).time(); 
$phone = $_POST["phone"];
$topic = explode("-",$email );
$itemName = 'images-'.$UUID;
//$topicArn = $_POST["topicArn"];
$qurl = $_POST["qurl"];
$_SESSION['queueurl']=$qurl;
//$_SESSION['topicArn']=$topicArn;

// These are for subscription checks purposes
$alreadySubscribed = FALSE;
$phone_nodashes = str_replace("-", "", $phone);
$phone_flag = FALSE;
$email_flag = FALSE;
$topicArn= '';
#############################################
# Check if user already subscribed
##############################################

$resultTopics = $snsclient->listTopics();

foreach ($resultTopics->getPath('Topics') as $topic_n) {
	
	###################################################
	#  Check if already subscribed and change the FLAGS
	###################################################
	$topicArn1 = $topic_n['TopicArn'];
	$result = $snsclient->listSubscriptionsByTopic(array(
	    // TopicArn is required
	    'TopicArn' => $topicArn1,
	));

	foreach ($result->getPath('Subscriptions') as $subscription) {

		$sArn = $subscription['SubscriptionArn'];
		$endpoint = $subscription['Endpoint'];
		
		if (($endpoint == $email_original) || ($endpoint == $phone_nodashes)) { // If email and phone are found 
		$alreadySubscribed = TRUE;
		$topicArn = $topicArn1;
		echo "User already subscribed\n";	
			if ($sArn != 'PendingConfirmation'){ //This if is for not getting an error trying to access 'getSubscriptionAttributes()'
				$result1 = $snsclient->getSubscriptionAttributes(array(
				    // SubscriptionArn is required
				  'SubscriptionArn' => $sArn,
				));

				foreach ($result1->getPath('Attributes') as $attribute) {

				        $confirmation = $attribute['ConfirmationWasAuthenticated'];
				        if (($endpoint == $phone_nodashes) && ($confirmation == TRUE)) {
				                $phone_flag = TRUE;
				        } else if (($endpoint == $email_original) && ($confirmation == TRUE)) {
				                $email_flag = TRUE;
				        }
				}
			}
		}
	}
	
}

if ($alreadySubscribed == FALSE) {
	echo "User WAS NOT subscribed\n";
	################################################################
	# Create SNS Simple Notification Service Topic for subscription 
	################################################################

	$topicName="mp1rtgresize".$UUID;
	 
	$snsresult = $snsclient->createTopic(array(
	    // Name is required
	    'Name' => $topicName,
	));

	$topicArn = $snsresult['TopicArn'];

	$snsresult = $snsclient->setTopicAttributes(array(
	    // TopicArn is required
	    'TopicArn' => $topicArn,
	    // AttributeName is required
	    'AttributeName' => 'DisplayName',
	    'AttributeValue' => 'aws544',
	));

}

$_SESSION['topicArn'] = $topicArn;


##############################################
#  subscription SMS
##############################################

if (!$phone_flag) {

	try {
	$result = $snsclient->subscribe(array(
	    // TopicArn is required
	    'TopicArn' => $topicArn,
	    // Protocol is required
	    'Protocol' => 'sms',
	    'Endpoint' => $phone,
	)); } catch(InvalidParameterException $i) {
	 echo 'Invalid parameter at sms subsciption: '. $i->getMessage() . " $topicArn". "\n";
	} 
}

##############################################
#  subscription  email
##############################################

if (!$email_flag) {
	try {
	$result = $snsclient->subscribe(array(
	    // TopicArn is required
	    'TopicArn' => $topicArn,
	    // Protocol is required
	    'Protocol' => 'email',
	    'Endpoint' => $email_original,
	)); } catch(InvalidParameterException $i) {
	 echo 'Invalid parameter at email subscription: '. $i->getMessage() . " $topicArn". "\n";
	} 
}

######################
# Create S3 bucket
######################

$result = $client->createBucket(array(
    'Bucket' => $bucket
));

// Wait until the bucket is created
$client->waitUntil('BucketExists', array('Bucket' => $bucket));

$uploaddir = '/tmp/';
$uploadfile = $uploaddir . basename($_FILES['uploaded_file']['name']);
$success = '';

if (move_uploaded_file($_FILES['uploaded_file']['tmp_name'], $uploadfile)) {
    $success = "File is valid, and was successfully uploaded.";
} else {
    $success = "Possible file upload attack!";
}
$pathToFile = $uploaddir.$_FILES['uploaded_file']['name'];

// Upload an object by streaming the contents of a file
// $pathToFile should be absolute path to a file on disk
$result = $client->putObject(array(
    'ACL'        => 'public-read',
    'Bucket'     => $bucket,
    'Key'        => $_FILES['uploaded_file']['name'],
    'SourceFile' => $pathToFile,
    'Metadata'   => array(
        'timestamp' => time(),
        'md5' =>  md5_file($pathToFile),
    )
));

$url= $result['ObjectURL'];


###################################################
# Set simpleDB Domain
###################################################
$domain = "itm544rtg"; 
$_SESSION['domain']=$domain;
$result = $sdbclient->createDomain(array(
    // DomainName is required
    'DomainName' => $domain, 
));

$result = $sdbclient->putAttributes(array(
    // DomainName is required
    'DomainName' => $domain,
   // ItemName is required
    'ItemName' =>$itemName ,
    // Attributes is required
    'Attributes' => array(
        array(
           'Name' => 'rawurl',
           'Value' => $url,
	),	
	array(
           'Name' => 'bucket',
           'Value' => $bucket,
        ),
        array(
           'Name' => 'id',
           'Value' => $UUID,
        ),  
        array(
            'Name' =>  'email',
            'Value' => $_POST['email'],
        ),
	array(
            'Name' => 'phone',
            'Value' => $phone,
	),
         array(
            'Name' => 'finishedurl',
            'Value' => '',
        ),     
         array(
            'Name' => 'filename',
            'Value' => basename($_FILES['uploaded_file']['name']),
        ), 
    ),
));

$exp="select * from  $domain";

$result = $sdbclient->select(array(
    'SelectExpression' => $exp 
));

#####################################
# Code to add a Message to a queue 
#####################################

$result = $sqsclient->sendMessage(array(
    // QueueUrl is required
    'QueueUrl' => $qurl,
    // MessageBody is required
    'MessageBody' => $UUID,
    'DelaySeconds' => 15,
));

?>
<html>
<head>
	<title>Process PHP</title>
	<link href="bootstrap.css" rel="stylesheet" type="text/css">
</head>
<body>
	<div class="container-narrow">
		<fieldset id="customfieldset"><legend class="text-center" id="customlegend">Thank you!</legend>
		<h4 class="text-center"><? echo $success ?></h4>
		<h4 class="text-center">This is your Bucket name: <? echo $bucket ?></h4>
		<h4 class="text-center">Please wait... you will be redirected in 10~20 seconds!</h4>
		</fieldset>
	</div>
<script>
window.location = 'resize.php';
</script>
</body>
</html>


