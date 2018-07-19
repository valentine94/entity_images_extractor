<?php

namespace Drupal\entity_images_extractor\Service;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\file\FileInterface;

/**
 * Interface EntityImagesExtractorInterface.
 *
 * @package Drupal\entity_images_extractor\Service
 */
interface EntityImagesExtractorInterface extends ContainerInjectionInterface {

  /**
   * Set the current entity from the request.
   */
  public function setCurrentEntity();

  /**
   * Additional method to allow the processed entity being overridden.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Entity object.
   */
  public function setEntity(ContentEntityInterface $entity);

  /**
   * Set entity info using current entity object.
   */
  public function setEntityInfo();

  /**
   * Extract image entities.
   *
   * @return \Drupal\file\Entity\FileInterface[]|array
   *   Image file entities.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function extractImageEntities():array;

  /**
   * Get image's URL and mime type from it's file entity object.
   *
   * @param \Drupal\file\FileInterface $file
   *   Image's file entity object.
   *
   * @return array
   *   Image's URL and mime type.
   */
  public static function getImageUrlAndType(FileInterface $file);

}