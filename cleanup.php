<!DOCTYPE html>
<?php
########################################################
# RAFAEL TENA (A20314078)
# From: Bakery - Jeremy Hajek
# Notes:
# - Used sessions to retrieve some values
# - Added code to consume the queue
# - Added code to change the ACL of the object
# - Added code to destroy the session values
# - Added code to set the expiration time of the image
########################################################

session_start();

##############################################################
# Get the session values needed for the code
##############################################################

$bucket = $_SESSION['bucket'];
$newurl = $_SESSION['newurl'];
$rawurl = $_SESSION['rawurl'];
$newfilename = $_SESSION['newfilename'];
$receipthandle = $_SESSION['receipthandle'];
$queueURL = $_SESSION['queueurl'];
$topicArn = $_SESSION['topicArn'];

// Include the SDK using the Composer autoloader
require 'vendor/autoload.php';

use Aws\SimpleDb\SimpleDbClient;
use Aws\S3\S3Client;
use Aws\Sqs\SqsClient;
use Aws\Common\Aws;
use Aws\SimpleDb\Exception\InvalidQueryExpressionException;
use Aws\Sns\SnsClient;

//aws factory
$aws = Aws::factory('/var/www/vendor/aws/aws-sdk-php/src/Aws/Common/Resources/custom-config.php');

//Instantiate the S3 client with your AWS credentials and desired AWS region
$client = $aws->get('S3');

$sdbclient = $aws->get('SimpleDb');

$snsclient = $aws->get('Sns'); 

$sqsclient = $aws->get('Sqs');

##############################################################
# add code to consume the Queue to make sure the job is done
##############################################################

$result = $sqsclient->deleteMessage(array(
    // QueueUrl is required
    'QueueUrl' => $queueURL,
    // ReceiptHandle is required
    'ReceiptHandle' => $receipthandle,
));


##############################################################
# add code to send the SMS message of the finished S3 URL
##############################################################

	################################################################################
	# SNS publishing of message to topic - which will be sent via SMS ( and email )
	################################################################################
	$result = $snsclient->publish(array(
	    'TopicArn' => $topicArn,
	    'TargetArn' => $topicArn,
	    // Message is required
	    'Message' => 'Thank you. Your image has been uploaded and watermarked: '.$newurl,
	    'Subject' => $newurl,
	));

##############################################################
# Set object expire to remove the image in one day
##############################################################

$result = $client->putBucketLifecycle(array(
    // Bucket is required
    'Bucket' => $bucket,
    // Rules is required
    'Rules' => array(
	array(
	    'Expiration' => array(
	        'Days' => 1,
	    ),
	    // Prefix is required
	    'Prefix' => '',
	    // Status is required
	    'Status' => 'Enabled',
	    ),
	),
));

#####################################################
# - set ACL to public
#####################################################

$result = $client->putObjectAcl(array(
    'ACL' => 'public-read',
    // Bucket is required
    'Bucket' => $bucket,
    'Key' => $newfilename,
));

#####################################################
# - Destroy the session
#####################################################
unset($_SESSION);
$_SESSION = array();
session_destroy();

sleep(3);

?> 
<html>
<head>
	<title>Cleanup PHP</title>
	<link href="bootstrap.css" rel="stylesheet" type="text/css">
</head>
<body>
	<div class="container-narrow">
		<fieldset id="customfieldset"><legend class="text-center" id="customlegend">Thank you for using our services</legend>
		<h3>Before:</h3>
		<img src="<? echo $rawurl ?>" alt="before">
		<h3>After:</h3>
		<img src="<? echo $newurl ?>" alt="after">
		<p class="text-center"><a href="index.php" class="btn btn-primary">Return</a></p> 
		</fieldset>
	</div>
</body>
</html>
