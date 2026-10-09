<?php
/**
 * Plugin Name: BV Google Vision
 * Description: for image tagging and NSFW check
 * Author: Lai Consulting Team
 * Version: 0.2
 * GitLab Plugin URI: https://gitlab.com/beauvoyage/bv_google_vision
 */

# includes the autoloader for libraries installed with composer
require __DIR__ . '/vendor/autoload.php';

# Composer autoload does not work. Hence the manual loading
//require __DIR__ . '/vendor/google/auth/autoload.php';
require __DIR__ . '/vendor/google/cloud-vision/src/V1/ImageAnnotatorClient.php';

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
	$imageAnnotator = new ImageAnnotatorClient();

	# annotate the image
	//$image = file_get_contents($path);
	$response = $imageAnnotator->annotateImage($image,$features);
	$imageAnnotator->close();

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
	$badtags=$GLOBALS['BAD_TAGS'];
	$url=wp_get_attachment_image_src($media_id, 'large')[0];
	$tarray=google_detect_label($url);
	$TagArr=array();

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

//add_filter('wp_generate_attachment_metadata', 'txt_domain_delete_fullsize_image',100,3);
function txt_domain_delete_fullsize_image($metadata,$media_id,$context)
{                                                                                                                                                                                                               error_log(print_r('metadata '.json_encode($metadata),true));                                                                                                                                            $upload_dir = wp_upload_dir();                                                                                                                                                                          //$full_image_path = trailingslashit($upload_dir['basedir']) . $metadata['file'];                                                                                                                       //$full_image_path = trailingslashit($upload_dir['path']) . $metadata['original_image'];                                                                                                                $full_image_path = wp_get_original_image_path( $media_id );
        error_log(print_r('to delete '.$full_image_path,true));
        $deleted = unlink($full_image_path);
        error_log(print_r('delete? '.$deleted,true));

        return $metadata;
}
