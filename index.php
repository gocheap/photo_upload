<!DOCTYPE html>
<html>
<!--
RAFAEL TENA (A20314078)
From: Bakery - Jeremy Hajek
Notes:
- Read and understood all the code
- Changed Topic name name to mine
- Included the hidden input for the queue url
-->
<head>
	<title>Opening Page</title>
	<link href="bootstrap.css" rel="stylesheet" type="text/css">
</head>

<body>
<?php


// Include the SDK using the Composer autoloader
require 'vendor/autoload.php';

use Aws\Sqs\SqsClient;
use Aws\Common\Aws;

//aws factory
$aws = Aws::factory('/var/www/vendor/aws/aws-sdk-php/src/Aws/Common/Resources/custom-config.php'); 
$sqsclient = $aws->get('Sqs');


#############################################
# Create SQS
##############################################

$sqsresult = $sqsclient->createQueue(array('QueueName' => 'photo_queue',));

$qurl=$sqsresult['QueueUrl'];

?>
<div class="container-narrow">

<fieldset id="customfieldset"><legend class="text-center" id="customlegend">Picture Uploader</legend>
<form class ="form-horizontal" action="process.php" method="post" enctype="multipart/form-data">
	<div class="control-group">
	<label class="control-label" for="email">Email: </label><div class="controls"><input type="text" name="email" id="email"></div>
	</div>
	<div class="control-group">
	<label class="control-label" for="phone">Cell Number: </label><div class="controls"><input type="text" name="phone" id="phone"> </div>
	</div>	
	<div class="control-group">
	<label class="control-label" for="uploaded_file">Choose Image: </label><div class="controls"><input type="file" name="uploaded_file" id="uploaded_file"> </div>  
	</div>
	  <!--<input type="hidden" name="topicArn" value="<? //echo $topicArn ?>" >-->
	  <input type="hidden" name="qurl" value="<? echo $qurl ?>" > 
	<div class="control-group">	
	<div class="controls"><input class="btn btn-success" type="submit" name="submit" value="Submit it!" >
	</div>
</form>
</fieldset>
</div>
</body>

</html>
