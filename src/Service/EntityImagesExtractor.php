<?php

namespace Drupal\entity_images_extractor\Service;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\file\FileInterface;
use Drupal\file\Plugin\Field\FieldType\FileFieldItemList;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class EntityImagesExtractor.
 *
 * Helper service for extracting all possible images from current entity.
 * Or specified entity as well.
 *
 * @package Drupal\entity_images_extractor\Service
 */
class EntityImagesExtractor implements EntityImagesExtractorInterface {

  /**
   * Entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;
  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;
  /**
   * Entity repository.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected $entityRepository;
  /**
   * Current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;
  /**
   * Current entity if any.
   *
   * @var \Drupal\Core\Entity\ContentEntityInterface|null
   */
  protected $entity;
  /**
   * Entity info array.
   *
   * @var array
   */
  protected $entityInfo;

  /**
   * EntityImagesExtractor constructor.
   *
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   Entity field manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   Entity type manager.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   Entity repository.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   Route match.
   */
  public function __construct(
    EntityFieldManagerInterface $entity_field_manager,
    EntityTypeManagerInterface $entity_type_manager,
    EntityRepositoryInterface $entity_repository,
    RouteMatchInterface $route_match
  ) {
    $this->entityFieldManager = $entity_field_manager;
    $this->entityTypeManager  = $entity_type_manager;
    $this->entityRepository   = $entity_repository;
    $this->routeMatch         = $route_match;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container):EntityImagesExtractor {
    return new static(
      $container->get('entity_field.manager'),
      $container->get('entity_type.manager'),
      $container->get('entity.repository'),
      $container->get('current_route_match')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function setEntityFromRequest() {
    $this->entity = NULL;
    $parameters   = $this->routeMatch->getParameters();
    $entity_types = array_keys($this->entityTypeManager->getDefinitions());
    foreach ($entity_types as $entity_type) {
      if ($parameters->has($entity_type)) {
        $this->entity = $parameters->get($entity_type);
        break;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function setEntity(ContentEntityInterface $entity) {
    // Set new entity object for processing.
    $this->entity = $entity;
    // Trigger set entity info to override/update it as well.
    $this->setEntityInfo();
  }

  /**
   * {@inheritdoc}
   */
  public function setEntityInfo() {
    if ($this->entity instanceof ContentEntityInterface) {
      $this->entityInfo = [
        'entity_type' => (string) $this->entity->getEntityTypeId(),
        'bundle'      => (string) $this->entity->bundle(),
      ];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function extractImageEntities():array {
    $entities = [];
    // Prevent any processing on the "non-nodes" pages.
    if (!$this->entity instanceof ContentEntityInterface) {
      return $entities;
    }
    // Handle image fields.
    $this->processImageFields($entities);
    // Handle text fields(HTML's <img> tags).
    $this->processTextFields($entities);
    return $entities;
  }

  /**
   * {@inheritdoc}
   */
  public static function getImageUrlAndType(FileInterface $file):array {
    return [
      'url'  => file_create_url($file->getFileUri()),
      'type' => $file->getMimeType(),
    ];
  }

  /**
   * Collect field machine names by type.
   *
   * Also depends on current entity type and bundle.
   *
   * @param string $field_type
   *   Field type.
   *
   * @return array
   *   Field machine names array.
   */
  protected function collectFieldsByType(string $field_type):array {
    $fields = [];
    // Get fields map by field type and entity type.
    $fields_map = $this->getFieldsMap($field_type);
    // Fetch all field machine names which are exist in the current bundle.
    foreach ($fields_map as $field_name => $data) {
      if (in_array($this->getEntityBundle(), $data['bundles'])) {
        $fields[] = $field_name;
      }
    }
    return $fields;
  }

  /**
   * Get fields map.
   *
   * @param string $field_type
   *   Field type.
   *
   * @return array
   *   Image map array.
   */
  protected function getFieldsMap(string $field_type):array {
    $entity_type = $this->getEntityType();
    $map = $this->entityFieldManager->getFieldMapByFieldType($field_type);
    return empty($map) || !isset($map[$entity_type]) ? [] : $map[$entity_type];
  }

  /**
   * Get entity type ID.
   *
   * @return string
   *   Entity type ID.
   */
  protected function getEntityType():string {
    return (string) $this->entityInfo['entity_type'];
  }

  /**
   * Get entity bundle.
   *
   * @return string
   *   Entity bundle machine name.
   */
  protected function getEntityBundle():string {
    return (string) $this->entityInfo['bundle'];
  }

  /**
   * Extract images dom elements from field's HTML.
   *
   * @param string $html
   *   Field's HTML.
   *
   * @return NULL|\DOMNodeList
   *   All image dom elements array.
   */
  protected function extractImages(string $html) {
    $images = $this->loadHtmlAsDom($html)
      ->getElementsByTagName('img');
    return $images instanceof \DOMNodeList ? $images : NULL;
  }

  /**
   * Load HTML string as the \DOMDocument object.
   *
   * @param string $html
   *   HTML string.
   *
   * @return \DOMDocument
   *   \DOMDocument object.
   */
  protected function loadHtmlAsDom(string $html):\DOMDocument {
    $doc = new \DOMDocument();
    $doc->loadHTML($html);
    return $doc;
  }

  /**
   * Extract attributes of specific image tag.
   *
   * @param \DOMElement $image
   *   Image's tag dom element.
   *
   * @return array
   *   Image's attributes array.
   */
  protected function extractImageAttributes(\DOMElement $image):array {
    $attributes = [];
    // Ensure element has attributes at all.
    if ($image->hasAttributes()) {
      /** @var \DOMNamedNodeMap $attribute */
      // Loop through all existing element attributes.
      foreach ($image->attributes as $attribute) {
        // Store all attributes in name => value format.
        $attributes[$attribute->nodeName] = $attribute->nodeValue;
      }
    }
    return $attributes;
  }

  /**
   * Process image fields.
   *
   * @param array &$entities
   *   Result entities array to be inserted in.
   */
  protected function processImageFields(array &$entities) {
    $image_fields = $this->collectFieldsByType('image');
    if (!empty($image_fields)) {
      foreach ($image_fields as $image_field) {
        if ($this->entity->hasField($image_field)) {
          $field = $this->entity->get($image_field);
          if ($field instanceof FileFieldItemList) {
            $entities += (array) $field->referencedEntities();
          }
        }
      }
    }
  }

  /**
   * Process text fields.
   *
   * @param array &$entities
   *   Result entities array to be inserted in.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function processTextFields(array &$entities) {
    $text_fields = $this->prepareTextFields();
    if (!empty($text_fields)) {
      foreach ($text_fields as $text_field) {
        if ($this->entity->hasField($text_field)) {
          $field = $this->entity->get($text_field);
          if ($field instanceof FieldItemListInterface) {
            // Do not operate on empty fields.
            if ($field->isEmpty()) {
              continue;
            }
            $html = static::getHtmlValueFromTextField($field);
            if (!empty($html)) {
              $images = $this->extractImages($html);
              if ($images instanceof \DOMNodeList) {
                foreach ($images as $image) {
                  if ($image instanceof \DOMElement) {
                    $attributes = $this->extractImageAttributes($image);
                    /** @var \Drupal\file\FileInterface $entity */
                    $entity = $this->getEntityFromImageAttributes($attributes);
                    $entities[$entity->id()] = $entity;
                  }
                }
              }
            }
          }
        }
      }
    }
  }

  /**
   * Get HTML value from the text field.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $field
   *   Text field object.
   *
   * @return null|string
   *   HTML string or NULL.
   */
  protected static function getHtmlValueFromTextField(FieldItemListInterface $field) {
    $html   = NULL;
    $values = $field->getValue();
    if (!empty($values)) {
      $html = '';
      foreach ($values as $value) {
        if (isset($value['value']) && !empty($value['value'])) {
          // Concatenate all HTMLs into one string.
          $html .= (string) $value['value'];
        }
      }
    }
    return $html;
  }

  /**
   * Prepare all type of text fields machine names.
   *
   * @return array
   *   Text field names.
   */
  protected function prepareTextFields():array {
    $text_fields = [];
    foreach (['text', 'text_long', 'text_with_summary'] as $type) {
      $text_fields += $this->collectFieldsByType($type);
    }
    return $text_fields;
  }

  /**
   * Get file object from image's uuid attribute.
   *
   * @param array $attributes
   *   Image tag attributes array.
   *
   * @return \Drupal\file\Entity\FileInterface|null
   *   Image file entity.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function getEntityFromImageAttributes(array $attributes) {
    $uuid = $attributes['data-entity-uuid'];
    return $this->getFileByUuid($uuid);
  }

  /**
   * Get file entity by UUID.
   *
   * @param string $uuid
   *   UUID value.
   *
   * @return \Drupal\file\Entity\FileInterface|\Drupal\Core\Entity\EntityInterface|null
   *   File entity.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function getFileByUuid(string $uuid) {
    $entity = $this->entityRepository
      ->loadEntityByUuid('file', $uuid);
    return $entity instanceof FileInterface ? $entity : NULL;
  }

}
