<?php
/**
 * Plugin Name: BV Google Vision
 * Description: for image tagging and NSFW check
 * Author: Lai Consulting Team
 * Version: 0.3
 * License: GPL-2.0-or-later
 * GitHub Plugin URI: https://github.com/lai-team/bv_google_vision
 *
 * @package BV_Google_Vision
 */

defined( 'ABSPATH' ) || exit;

# includes the autoloader for libraries installed with composer
require_once __DIR__ . '/vendor/autoload.php';

# Composer autoload does not work. Hence the manual loading.
# require_once, not require: if the autoloader has already resolved the class
# this is otherwise a duplicate-class fatal. It was safe only because
# autoloading is lazy and nothing touched the class before this line.
//require __DIR__ . '/vendor/google/auth/autoload.php';
require_once __DIR__ . '/vendor/google/cloud-vision/src/V1/ImageAnnotatorClient.php';

# imports the Google Cloud client library
use Google\Cloud\Vision\V1\ImageAnnotatorClient;
use Google\Cloud\Vision\V1\Feature;
use Google\Cloud\Vision\V1\Feature\Type;

//global $BAD_TAGS;
$BAD_TAGS=array('body of water', 'water', 
	'face','nature','dog','cat',
	'canidae', 'mammal', 'vertebrate',
	'water resources', 'watercourse', 
	'blue','people','no person','one',
	'adult','portrait','woman');

/*
LANDMARK_DETECTION 	Run landmark detection.
LABEL_DETECTION 	Run label detection.
SAFE_SEARCH_DETECTION 	Run SafeSearch to detect potentially unsafe or undesirable content.
 */

function google_detect_label($image)
{
        $feature_obj1=new Feature;
        $feature_obj1->setType(Type::LABEL_DETECTION);
        $feature_obj1->setModel('builtin/latest');

        $feature_obj2=new Feature;
        $feature_obj2->setType(Type::LANDMARK_DETECTION);
        $feature_obj2->setModel('builtin/latest');

        $feature_obj3=new Feature;
        $feature_obj3->setType(Type::SAFE_SEARCH_DETECTION);
        $feature_obj3->setModel('builtin/latest');

        //logErrors( 'fEAture ' . json_encode( get_class_methods($feature_obj1) ));
        //logErrors( 'fEAture ' . json_encode( $feature_obj->getType));
        $features = [$feature_obj1,$feature_obj2,$feature_obj3];
	//$features = [Type::SAFE_SEARCH_DETECTION,Type::LABEL_DETECTION,Type::LANDMARK_DETECTION];
	//print_r($features);
	//error_log(print_r('google label: '. $image,true));
	# instantiates a client
	#
	# Both the constructor and annotateImage() throw: the constructor when the
	# credential named by GOOGLE_APPLICATION_CREDENTIALS is missing, unreadable
	# or expired, annotateImage() on quota, permission and network errors.
	# Neither was caught, so any of those turned every upload into a fatal.
	# Catch Throwable rather than Exception -- the gRPC and protobuf layers
	# raise Error subclasses too.
	$imageAnnotator = null;

	try {
		$imageAnnotator = new ImageAnnotatorClient();
		$response       = $imageAnnotator->annotateImage( $image, $features );
	} catch ( \Throwable $e ) {
		error_log( 'bv_google_vision: Vision API call failed: ' . $e->getMessage() );
		return array( 'NSFW' => array(), 'Labels' => array(), 'Landmarks' => array(), 'Locations' => array() );
	} finally {
		if ( $imageAnnotator ) {
			try {
				$imageAnnotator->close();
			} catch ( \Throwable $e ) {
				// Closing a client that never connected is not worth failing over.
				error_log( 'bv_google_vision: closing Vision client failed: ' . $e->getMessage() );
			}
		}
	}

	$landmarks = $response->getLandmarkAnnotations();
	$labels = $response->getLabelAnnotations();
	$safe = $response->getSafeSearchAnnotation();

	$arr_labels=array();
	$arr_landmarks=array();
	$arr_locations=array();
	$arr_safe=array();

	if ($labels) {
		foreach ($labels as $label) {
			$arr_labels+=[$label->getDescription() => $label->getScore()] ;
		}
		//upload.wikimedia.org/wikipedia/commons/8/85/Tour_Eiffel_Wikimedia_Commons_%28cropped%29.jpg}
	}

	if ($safe){
		$adult = $safe->getAdult();
		$violence = $safe->getViolence();
		//$medical = $safe->getMedical();
		//$spoof = $safe->getSpoof();
		//$racy = $safe->getRacy();

		$arr_safe=['adult'=> $adult/5 , 'violence' => $violence/5 ];
	}

	if ($landmarks){
		foreach ($landmarks as $landmark) {
			$arr_landmarks+=[$landmark->getDescription() => $landmark->getScore()];
			$arr_locations+=[$landmark->getDescription() =>
				['lng'=>$landmark->getLocations()[0]->getLatLng()->getLongitude(),
				'lat'=>$landmark->getLocations()[0]->getLatLng()->getLatitude()]];
		}
	}

	$tag_array = [ 'NSFW'=> $arr_safe, 'Labels'=> $arr_labels, 'Landmarks'=> $arr_landmarks, 'Locations'=>$arr_locations ];
	return $tag_array;
}

add_filter('wp_generate_attachment_metadata', 'bv_update_imagetags',10,3);
function bv_update_imagetags($metadata,$media_id,$context) {
	// wp_generate_attachment_metadata fires for every attachment, not just
	// images. wp_get_attachment_image_src() returns false for a PDF or a
	// video, so [0] on it warned on PHP 8 and then sent false to Vision --
	// a billable API call per non-image upload.
	if ( ! wp_attachment_is_image( $media_id ) ) {
		return $metadata;
	}

	$src = wp_get_attachment_image_src( $media_id, 'large' );

	if ( ! is_array( $src ) || empty( $src[0] ) ) {
		return $metadata;
	}

	$badtags = isset( $GLOBALS['BAD_TAGS'] ) && is_array( $GLOBALS['BAD_TAGS'] ) ? $GLOBALS['BAD_TAGS'] : array();
	$url     = $src[0];
	$tarray  = google_detect_label( $url );
	$TagArr  = array();

	#add_post_meta( $media_id, 'nsfw',max($tarray['NSFW'] ));     
	if( !empty(array_filter($tarray['NSFW'],function($score){return $score >0.9;}))) $TagArr+=['NSFW'=>1];

	if( $tarray['Labels']){
		add_post_meta( $media_id, 'google_labels',$tarray['Labels'] );     
		$TagArr+=array_filter( 
			array_filter($tarray['Labels'],function($score){return $score > 0.95;}),
				function($key) use(&$badtags){
					//error_log(print_r('bad tags: ' . json_encode($badtags),true));
					return !in_array(strtolower($key),$badtags);
				}, ARRAY_FILTER_USE_KEY 
		);
		//error_log(print_r('tag array: '. json_encode($TagArr),true));
	}

	if( $tarray['Landmarks']){
		add_post_meta( $media_id, 'google_landmarks',$tarray['Landmarks'] );     
		$landmarks_selected=array_filter($tarray['Landmarks'],function($score){return $score > 0.8;})??array();

		if( empty(get_post_meta($media_id,'geom_point',true)) && !empty($tarray['Locations']) ){    
			$Location=$tarray['Locations'][array_key_first($tarray['Locations'])];
			//update the geom_point of an image                                                                                                             
			update_post_meta($media_id, 'geom_point',
				'{"type":"Feature","properties":{"landmark": "'. array_keys($tarray["Landmarks"])[0] .'"},"geometry":{"type":"Point","coordinates":['. $Location["lng"].', '. $Location["lat"] .']}}'
			);    

			if(empty($landmarks_selected)) $landmarks_selected+=[
				array_key_first($tarray['Landmarks'])=>$tarray['Landmarks'][array_key_first($tarray['Landmarks'])]
			];
		}     
		$TagArr+=$landmarks_selected;
	}

	if( !empty($TagArr)) wp_set_post_tags( $media_id, array_keys($TagArr), true );
	return $metadata;
}

/*
 * txt_domain_delete_fullsize_image() lived here. It called
 * unlink( wp_get_original_image_path( $media_id ) ) with no existence check,
 * no capability check and no way back, deleting the user's original upload
 * permanently. Its add_filter was commented out, so it never ran -- but it
 * sat one uncommented line away from destroying every original image on
 * upload, and unlink() on a missing path warns as well.
 *
 * Removed rather than guarded: nothing in the install referenced it, and
 * whatever disk-space problem it was written for should be solved with a
 * deliberate, reversible media cleanup, not a filter on every upload.
 */
