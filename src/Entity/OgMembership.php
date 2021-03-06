<?php

namespace Drupal\og\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\og\Og;
use Drupal\og\OgGroupAudienceHelper;
use Drupal\og\OgMembershipInterface;

/**
 * The membership entity that connects a group and a user.
 *
 * When dealing with non-user entities that are group content, that is content
 * that is associated with a group, we do it via an entity reference field that
 * has the default storage. The only information that we hold is that a group
 * content is referencing a group.
 *
 * However, when dealing with the user entity we recognize that we need to
 * special case it. It won't suffice to just hold the reference between the user
 * and the group content as it will be laking crucial information such as: the
 * state of the user's membership in the group (active, pending or blocked), the
 * time the membership was created, the user's OG role in the group, etc.
 *
 * For this meta data we have the fieldable OgMembership entity, that is always
 * connecting between a user and a group. There cannot be an OgMembership entity
 * connecting two non-user entities.
 *
 * Creating such a relation is done for example in the following way:
 *
 * @code:
 *  $values = ['type' => \Drupal\og\OgMembershipInterface::TYPE_DEFAULT];
 *  $membership = OgMembership::create($values);
 *  $membership
 *    ->setUser($user)
 *    ->setGroup($entity)
 *    ->setFieldName(OgGroupAudienceHelper::DEFAULT_FIELD)
 *    ->save();
 * @endcode
 *
 * Notice how the relation of the user to the group also includes the OG
 * audience field name this association was done by. Like this we are able to
 * express different membership types such as the default membership that comes
 * out of the box, or a "premium membership" that can be for example expired
 * after a certain amount of time (the logic for the expired membership in the
 * example is out of the scope of OG core).
 *
 * Having this field separation is what allows having multiple OG audience
 * fields attached to the user, where each group they are associated with may be
 * a result of different membership types.
 *
 * @ContentEntityType(
 *   id = "og_membership",
 *   label = @Translation("OG membership"),
 *   bundle_label = @Translation("OG membership type"),
 *   module = "og",
 *   base_table = "og_membership",
 *   fieldable = TRUE,
 *   bundle_entity_type = "og_membership_type",
 *   entity_keys = {
 *     "id" = "id",
 *     "bundle" = "type",
 *   },
 *   bundle_keys = {
 *     "bundle" = "type"
 *   },
 *   handlers = {
 *     "views_data" = "Drupal\og\OgMembershipViewsData",
 *   }
 * )
 */
class OgMembership extends ContentEntityBase implements OgMembershipInterface {

  /**
   * {@inheritdoc}
   */
  public function getCreatedTime() {
    return $this->get('created')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setCreatedTime($timestamp) {
    $this->set('created', $timestamp);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getUser() {
    return $this->get('uid')->entity;
  }

  /**
   * {@inheritdoc}
   */
  public function setUser(AccountInterface $user) {
    $this->set('uid', $user->id());
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setFieldName($fieldName) {
    $this->set('field_name', $fieldName);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getFieldName() {
    return $this->get('field_name')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setGroup(EntityInterface $group) {
    $this->set('entity_type', $group->getEntityTypeId());
    $this->set('entity_id', $group->id());
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getGroupEntityType() {
    return $this->get('entity_type')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getGroupId() {
    return $this->get('entity_id')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getGroup() {
    $entity_type = $this->get('entity_type')->value;
    $entity_id = $this->get('entity_id')->value;
    return \Drupal::entityTypeManager()->getStorage($entity_type)->load($entity_id);
  }

  /**
   * {@inheritdoc}
   */
  public function setState($state) {
    $this->set('state', $state);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getState() {
    return $this->get('state')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getType() {
    return $this->bundle();
  }

  /**
   * {@inheritdoc}
   */
  public function setRoles(array $roles = array()) {
    $role_ids = array_map(function (OgRole $role) {
      return $role->id();
    }, $roles);

    $this->set('roles', array_unique($role_ids));

    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function addRole(OgRole $role) {
    $roles = $this->getRoles();
    $roles[] = $role;

    return $this->setRoles($roles);
  }

  /**
   * {@inheritdoc}
   */
  public function revokeRole(OgRole $role) {
    $roles = $this->getRoles();

    foreach ($roles as $key => $existing_role) {
      if ($existing_role->id() == $role->id()) {
        unset($roles[$key]);

        // We can stop iterating.
        break;
      }
    }

    return $this->setRoles($roles);
  }

  /**
   * {@inheritdoc}
   */
  public function getRoles() {
    return $this->get('roles')->referencedEntities();
  }

  /**
   * {@inheritdoc}
   */
  public function getRolesIds() {
    return array_map(function (OgRole $role) {
      return $role->id();
    }, $this->getRoles());
  }

  /**
   * {@inheritdoc}
   */
  public function hasPermission($permission) {
    return array_filter($this->getRoles(), function (OgRole $role) use ($permission) {
      return $role->hasPermission($permission);
    });
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = array();

    $fields['id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('ID'))
      ->setDescription(t("The group membership's unique ID."))
      ->setReadOnly(TRUE)
      ->setSetting('unsigned', TRUE);

    $fields['uuid'] = BaseFieldDefinition::create('uuid')
      ->setLabel(t('UUID'))
      ->setDescription(t('The membership UUID.'))
      ->setReadOnly(TRUE);

    $fields['type'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Type'))
      ->setDescription(t('The bundle of the membership'))
      ->setSetting('target_type', 'og_membership_type');

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Member User ID'))
      ->setDescription(t('The user ID of the member.'))
      ->setSetting('target_type', 'user');

    $fields['entity_type'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Group entity type'))
      ->setDescription(t('The entity type of the group.'));

    $fields['entity_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Group entity id.'))
      ->setDescription(t("The entity ID of the group."));

    $fields['state'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('State'))
      ->setDescription(t("The state of the group content."))
      ->setDefaultValue(TRUE);

    $fields['roles'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Roles'))
      ->setDescription(t('The OG roles related to an OG membership entity.'))
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setSetting('target_type', 'og_role');

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Create'))
      ->setDescription(t('The Unix timestamp when the group content was created.'));

    $fields['field_name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Field name'))
      ->setDescription(t("The name of the field holding the group ID, the OG membership is associated with."))
      ->setDefaultValue(OgGroupAudienceHelper::DEFAULT_FIELD);

    $fields['language'] = BaseFieldDefinition::create('language')
      ->setLabel(t('Language'))
      ->setDescription(t('The {languages}.language of this membership.'));

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage) {

    if (!$this->getFieldName()) {
      $this->setFieldName(OgGroupAudienceHelper::DEFAULT_FIELD);
    }

    // Check the value directly rather than using the entity, if there is one.
    // This will watch actual empty values and '0'.
    if (!$this->get('uid')->target_id) {
      // Throw a generic logic exception as this will likely get caught in
      // \Drupal\Core\Entity\Sql\SqlContentEntityStorage::save and turned in an
      // EntityStorageException anyway.
      throw new \LogicException('OG membership can not be created for an empty or anonymous user.');
    }

    parent::preSave($storage);
  }

  /**
   * {@inheritdoc}
   */
  public function save() {
    $result = parent::save();

    // Reset internal cache.
    Og::reset();
    \Drupal::service('og.access')->reset();

    return $result;
  }

}
