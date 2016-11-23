<?php

namespace Drupal\flysystem_swift;

use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;

/**
 * Class SwiftAdapter
 *
 * Wrapper around the upstream Swift adapter, with Drupal-specific tweaks.
 *
 * @package Drupal\flysystem_swift
 */
class SwiftAdapter extends \Nimbusoft\Flysystem\OpenStack\SwiftAdapter {

  /**
   * Create a "directory," which is actually just a placeholder for later has()
   * calls. Drupal often validates destinations using is_dir() and similar,
   * so we follow a pattern similar to Amazon S3, creating placeholders.
   *
   * @param string $dirname
   * @param \League\Flysystem\Config $config
   * @return array|false
   */
  public function createDir($dirname, Config $config) {
    return $this->write($dirname . '/', '', $config);
  }

  /**
   * Determine if an object exists. Prior to failing, attempt to match a
   * "directory" placeholder created by self::createDir().
   *
   * @param string $path
   * @return array|bool|null
   */
  public function has($path) {
    $return = parent::has($path);
    // Appease file.inc's calls to is_dir()
    return ($return !== FALSE) ? $return : parent::has($path . '/');
  }

  /**
   * Retrieve metadata; on initial failure, try to re-run the request as a
   * "directory" placeholder created by self::createDir().
   *
   * @param string $path
   * @return array|false
   */
  public function getMetadata($path) {
    // The underlying methods throw an exception if the object is not found.
    try {
      return parent::getMetadata($path);
    }
    catch (\Exception $e) {
      if (parent::getMetadata($path . '/')) {
        return [
          'type' => 'dir',
          'path' => $path,
          'timestamp' => REQUEST_TIME,
          'visibility' => AdapterInterface::VISIBILITY_PUBLIC,
        ];
      }
    }
    return FALSE;
  }

}
