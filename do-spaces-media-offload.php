<?php
/*
 * Plugin Name: DigitalOcean Spaces Media Offload
 * Description: Offloads WordPress media uploads to DigitalOcean Spaces and replaces URLs with the CDN URL.
 * Version:     1.0
 * Author:      Jason Ellis
 * Author       URI: https://jasonellis.ca
 * License:     GPLv2 or later
 * License URI: http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
*/

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

define('IMAGE_FILETYPES', ['jpg', 'jpeg', 'png', 'gif']);
// define('S3_REGION', 'YOUR_REGION');
// define('S3_ENDPOINT', 'YOUR_ENDPOINT');
// define('S3_BUCKET', 'YOUR_BUCKET_NAME');
// define('DO_KEY', 'YOUR_DO_KEY');
// define('DO_SECRET', 'YOUR_DO_SECRET');
// define('S3_URL', 'YOUR_CDN_URL');

// Initialize S3 client
function initialize_s3_client() {
  return new S3Client([
    'version' => 'latest',
    'http' => [
      'verify' => false,
      'timeout' => 30,
      'connect_timeout' => 5,
    ],
    'region' => S3_REGION,
    'endpoint' => S3_ENDPOINT,
    'credentials' => [
      'key' => DO_KEY,
      'secret' => DO_SECRET,
    ],
    'retries' => 3,
  ]);
}

// Hook to add media to DO Space on upload
add_action('add_attachment', 'do_spaces_bucket_add');
function do_spaces_bucket_add($attachment_id) {
  require ABSPATH . 'vendor/autoload.php';
  $client = initialize_s3_client();
  $errors = 0;

  // Set up the file to upload
  $file_path = get_attached_file($attachment_id);
  $file_info = pathinfo($file_path);
  $mime_type = mime_content_type($file_path); // Get MIME type

  // Check file type and upload accordingly
  if ( !in_array($file_info['extension'], IMAGE_FILETYPES) ) {
    $file_name = 'uploads/' . date('Y/m') . '/' . $file_info['basename'];
    $file_handle = fopen($file_path, 'r');

    if ($file_handle) {
      try {
        $result = $client->putObject([
          'Bucket' => S3_BUCKET,
          'Key'    => $file_name,
          'ACL'    => 'public-read',
          'Body'   => $file_handle,
          'ContentType' => $mime_type, // Set MIME type
        ]);
        fclose($file_handle);
      } catch (AwsException $e) {
        error_log("Error uploading file: " . $file_path . " - " . $e->getMessage());
        $errors++;
      }
    }
    else {
      error_log("Failed to open file: " . $file_path);
      $errors++;
    }
  }
  else {
    $file_metadata = wp_generate_attachment_metadata($attachment_id, $file_path);
    $upload_path = substr( $file_metadata['file'], 0, strrpos($file_metadata['file'], '/') );
    $file_name = 'uploads/' . $upload_path . '/' . $file_info['basename'];
    $file_sizes = array_values($file_metadata['sizes']);
    $file_handle = fopen($file_path, 'r');

    if ($file_handle) {
      try {
        $result = $client->putObject([
          'Bucket' => S3_BUCKET,
          'Key'    => $file_name,
          'ACL'    => 'public-read',
          'Body'   => $file_handle,
          'ContentType' => $mime_type, // Set MIME type
        ]);
        fclose($file_handle);
      } catch (AwsException $e) {
        error_log("Error uploading file: " . $file_path . " - " . $e->getMessage());
        $errors++;
      }
    }
    else {
      error_log("Failed to open file: " . $file_path);
      $errors++;
    }

    // Upload thumbnails
    foreach ($file_sizes as $file_size) {
      $thumbnail_path = $file_info['dirname'] . '/' . $file_size['file'];
      $thumbnail_handle = fopen($thumbnail_path, 'r');
      $thumbnail_mime_type = mime_content_type($thumbnail_path);

      if ($thumbnail_handle) {
        try {
          $result = $client->putObject([
            'Bucket' => S3_BUCKET,
            'Key'    => 'uploads/' . $upload_path . '/' . $file_size['file'],
            'ACL'    => 'public-read',
            'Body'   => $thumbnail_handle,
            'ContentType' => $thumbnail_mime_type, // Set MIME type for thumbnails
          ]);
          fclose($thumbnail_handle);
        } catch (AwsException $e) {
          error_log("Error uploading thumbnail: " . $thumbnail_path . " - " . $e->getMessage());
          $errors++;
        }
      }
      else {
        error_log("Failed to open thumbnail: " . $thumbnail_path);
        $errors++;
      }
    }
  }
}

// Hook to delete media from DO Space on deletion
add_action('delete_attachment', 'do_spaces_bucket_delete');
function do_spaces_bucket_delete($attachment_id) {
  require ABSPATH . 'vendor/autoload.php';
  $client = initialize_s3_client();
  $errors = 0;

  // Set up the AWS SDK for PHP client
  $client = initialize_s3_client();

  $file_path = get_attached_file($attachment_id);
  $file_info = pathinfo($file_path);

  if ( !in_array($file_info['extension'], IMAGE_FILETYPES) ) {
    $file_name = 'uploads/' . date('Y/m') . '/' . $file_info['basename'];

    try {
      $result = $client->deleteObject([
        'Bucket' => S3_BUCKET,
        'Key'    => $file_name,
      ]);
    } catch (AwsException $e) {
      error_log("Error deleting file: " . $file_path . " - " . $e->getMessage());
      $errors++;
    }
  }
  else {
    $file_metadata = wp_generate_attachment_metadata($attachment_id, $file_path);
    $upload_path = substr( $file_metadata['file'], 0, strrpos($file_metadata['file'], '/') );
    $file_name = 'uploads/' . $upload_path . '/' . $file_info['basename'];
    $file_sizes = array_values($file_metadata['sizes']);

    try {
      $result = $client->deleteObject([
        'Bucket' => S3_BUCKET,
        'Key'    => $file_name,
      ]);
    } catch (AwsException $e) {
      error_log("Error deleting file: " . $file_path . " - " . $e->getMessage());
      $errors++;
    }

    foreach ($file_sizes as $file_size) {
      $thumbnail_key = 'uploads/' . $upload_path . '/' . $file_size['file'];
      try {
        $result = $client->deleteObject([
          'Bucket' => S3_BUCKET,
          'Key'    => $thumbnail_key,
        ]);
      } catch (AwsException $e) {
        error_log("Error deleting thumbnail: " . $thumbnail_key . " - " . $e->getMessage());
        $errors++;
      }
    }
  }
}

// Replace URLs with CDN URL
add_filter('wp_get_attachment_url', 'replace_media_url');
function replace_media_url($url) {
  $path = 'uploads';
  if (strpos($url, 'wp-content/uploads') !== false) {
    $url = str_replace('wp-content/uploads', $path, $url);
    $url = str_replace(site_url(), S3_URL, $url);
  }
  return $url;
}

// Replace srcset URLs with CDN URL
add_filter('wp_calculate_image_srcset', 'replace_media_srcset', 10, 5);
function replace_media_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id) {
  $path = 'uploads';
  foreach ($sources as &$source) {
    $source['url'] = str_replace('wp-content/uploads', $path, $source['url']);
    $source['url'] = str_replace(site_url(), S3_URL, $source['url']);
  }
  return $sources;
}
