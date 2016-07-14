<?php

namespace Drupal\Tests\og\Kernel\Access;

use Drupal\comment\Entity\CommentType;
use Drupal\Component\Utility\Unicode;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use Drupal\og\Entity\OgMembership;
use Drupal\og\Entity\OgRole;
use Drupal\og\Og;
use Drupal\og\OgGroupAudienceHelper;
use Drupal\og\OgMembershipInterface;
use Drupal\og\OgRoleInterface;
use Drupal\user\Entity\User;

/**
 * Test access to group content operations for group members.
 *
 * @group og
 */
class OgGroupContentOperationAccessTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'comment',
    'entity_test',
    'field',
    'node',
    'og',
    'system',
    'user',
  ];

  /**
   * An array of test users.
   *
   * @var \Drupal\user\Entity\User[]
   */
  protected $users;

  /**
   * A test group.
   *
   * @var \Drupal\entity_test\Entity\EntityTest
   */
  protected $group;

  /**
   * The bundle ID of the test group.
   *
   * @var string
   */
  protected $groupBundle;

  /**
   * An array of test roles.
   *
   * @var \Drupal\og\Entity\OgRole[]
   *   Note that we're not using OgRoleInterface because of a class inheritance
   *   limitation in PHP 5.
   */
  protected $roles;

  /**
   * An array of test group content, keyed by bundle ID and user ID.
   *
   * @var \Drupal\Core\Entity\ContentEntityInterface[][]
   */
  protected $groupContent;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $this->installConfig(['og']);
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('comment');
    $this->installEntitySchema('node');
    $this->installEntitySchema('og_membership');
    $this->installEntitySchema('user');
    $this->installSchema('system', 'sequences');

    $this->entityTypeManager = $this->container->get('entity_type.manager');

    $this->groupBundle = Unicode::strtolower($this->randomMachineName());

    // Create a test user with UID 1. This user has universal access.
    $this->users['uid1'] = User::create(['name' => $this->randomString()]);
    $this->users['uid1']->save();

    // Create a user that will serve as the group owner. There are no special
    // permissions granted to the group owner, so this user can be used for
    // creating entities that are not owned by the user under test.
    $this->users['group_owner'] = User::create(['name' => $this->randomString()]);
    $this->users['group_owner']->save();

    // Declare that the test entity is a group type.
    Og::groupManager()->addGroup('entity_test', $this->groupBundle);

    // Create the test group.
    $this->group = EntityTest::create([
      'type' => $this->groupBundle,
      'name' => $this->randomString(),
      'user_id' => $this->users['group_owner']->id(),
    ]);
    $this->group->save();

    // Create 3 test roles with associated permissions. We will simulate a
    // project that has two group content types:
    // - 'newsletter_subscription': Any registered user can create entities of
    //   this type, even if they are not a member of the group.
    // - 'article': These can only be created by group members. Normal members
    //   can edit and delete their own articles, while admins can edit and
    //   delete any article.
    $permission_matrix = [
      OgRoleInterface::ANONYMOUS => [
        'create newsletter_subscription comment',
        'update own newsletter_subscription comment',
        'delete own newsletter_subscription comment',
      ],
      OgRoleInterface::AUTHENTICATED => [
        'create newsletter_subscription comment',
        'update own newsletter_subscription comment',
        'delete own newsletter_subscription comment',
        'create article content',
        'edit own article content',
        'delete own article content',
      ],
      // The administrator is not explicitly granted permission to edit or
      // delete their own group content. Having the 'any' permission should be
      // sufficient to also be able to edit their own content.
      OgRoleInterface::ADMINISTRATOR => [
        'create newsletter_subscription comment',
        'update any newsletter_subscription comment',
        'delete any newsletter_subscription comment',
        'create article content',
        'edit any article content',
        'delete any article content',
      ],
    ];

    foreach ($permission_matrix as $role_name => $permissions) {
      $role_id = "{$this->group->getEntityTypeId()}-{$this->group->bundle()}-$role_name";
      $this->roles[$role_name] = OgRole::load($role_id);
      foreach ($permissions as $permission) {
        $this->roles[$role_name]->grantPermission($permission);
      }
      $this->roles[$role_name]->save();

      // Create a test user with this role.
      $this->users[$role_name] = User::create(['name' => $this->randomString()]);
      $this->users[$role_name]->save();

      // Subscribe the user to the group.
      // Skip creation of the membership for the non-member user. It is actually
      // fine to save this membership, but in the most common use case this
      // membership will not exist in the database.
      if ($role_name !== OgRoleInterface::ANONYMOUS) {
        /** @var OgMembership $membership */
        $membership = OgMembership::create(['type' => OgMembershipInterface::TYPE_DEFAULT]);
        $membership
          ->setUser($this->users[$role_name]->id())
          ->setEntityId($this->group->id())
          ->setGroupEntityType($this->group->getEntityTypeId())
          ->addRole($this->roles[$role_name]->id())
          ->setState(OgMembershipInterface::STATE_ACTIVE)
          ->save();
      }
    }

    // Create a 'newsletter_subscription' group content type. We are using the
    // Comment entity for this to verify that this functionality works for all
    // entity types. We cannot use the 'entity_test' entity for this since it
    // has no support for bundles. Let's imagine that we have a use case where
    // the user can leave a comment with the text 'subscribe' in order to
    // subscribe to the newsletter.
    CommentType::create([
      'id' => 'newsletter_subscription',
      'label' => 'Newsletter subscription',
      'target_entity_type_id' => 'entity_test',
    ])->save();
    $settings = [
      'field_storage_config' => [
        'settings' => [
          'target_type' => 'entity_test',
        ],
      ],
    ];
    Og::createField(OgGroupAudienceHelper::DEFAULT_FIELD, 'comment', 'newsletter_subscription', $settings);

    // Create an 'article' group content type.
    NodeType::create([
      'type' => 'article',
      'name' => 'Article',
    ])->save();
    Og::createField(OgGroupAudienceHelper::DEFAULT_FIELD, 'node', 'article', $settings);

    // Create a group content entity owned by each test user, for both the
    // 'newsletter_subscription' and 'article' bundles.
    $user_ids = [
      'uid1',
      'group_owner',
      OgRoleInterface::ANONYMOUS,
      OgRoleInterface::AUTHENTICATED,
      OgRoleInterface::ADMINISTRATOR,
    ];
    foreach (['newsletter_subscription', 'article'] as $bundle_id) {
      foreach ($user_ids as $user_id) {
        $entity_type = $bundle_id === 'article' ? 'node' : 'comment';

        switch ($entity_type) {
          case 'node':
            $values = [
              'title' => $this->randomString(),
              'type' => $bundle_id,
              OgGroupAudienceHelper::DEFAULT_FIELD => [['target_id' => $this->group->id()]],
            ];
            break;

          case 'comment':
            $values = [
              'subject' => 'subscribe',
              'comment_type' => $bundle_id,
              'entity_id' => $this->group->id(),
              'entity_type' => 'entity_test',
              OgGroupAudienceHelper::DEFAULT_FIELD => [['target_id' => $this->group->id()]],
            ];
            break;
        }

        $entity = $this->entityTypeManager->getStorage($entity_type)->create($values);
        $entity->setOwner($this->users[$user_id]);
        $entity->save();

        $this->groupContent[$bundle_id][$user_id] = $entity;
      }
    }
  }

  /**
   * Test access to group content entity operations.
   *
   * @dataProvider accessProvider
   */
  public function testAccess($group_content_entity_type_id, $group_content_bundle_id, $expected_access_matrix) {
    /** @var \Drupal\og\OgAccessInterface $og_access */
    $og_access = $this->container->get('og.access');

    foreach ($expected_access_matrix as $user_id => $operations) {
      foreach ($operations as $operation => $ownerships) {
        foreach ($ownerships as $ownership => $expected_access) {
          // Depending on whether we're testing access to a user's own entity,
          // use either the entity owned by the user, or the one used by the
          // group owner.
          $entity = $ownership === 'own' ? $this->groupContent[$group_content_bundle_id][$user_id] : $this->groupContent[$group_content_bundle_id]['group_owner'];
          $user = $this->users[$user_id];
          $this->assertEquals($expected_access, $og_access->userAccessEntity($operation, $entity, $user)->isAllowed(), "Operation: $operation, ownership: $ownership, user: $user_id, bundle: $group_content_bundle_id");
        }
      }
    }
  }

  /**
   * Data provider for ::testAccess().
   *
   * @return array
   *   And array of test data sets. Each set consisting of:
   *   - A string representing the group content entity type ID upon which the
   *     operation is performed. Can be either 'node' or 'comment'.
   *   - A string representing the group content bundle ID upon which the
   *     operation is performed. Can be either 'newsletter_subscription' or
   *     'article'.
   *   - An array mapping the different users and the possible operations, and
   *     whether or not the user is expected to be granted access to this
   *     operation, depending on whether they own the content or not.
   */
  public function accessProvider() {
    return [
      [
        'comment',
        'newsletter_subscription',
        [
          // The super user and the administrator have the right to create,
          // update and delete any newsletter subscription.
          'uid1' => [
            'create' => ['any' => TRUE],
            'update' => ['own' => TRUE, 'any' => TRUE],
            'delete' => ['own' => TRUE, 'any' => TRUE],
          ],
          OgRoleInterface::ADMINISTRATOR => [
            'create' => ['any' => TRUE],
            'update' => ['own' => TRUE, 'any' => TRUE],
            'delete' => ['own' => TRUE, 'any' => TRUE],
          ],
          // Non-members and members have the right to subscribe to the
          // newsletter, and to manage or delete their own newsletter
          // subscriptions.
          OgRoleInterface::ANONYMOUS => [
            'create' => ['any' => TRUE],
            'update' => ['own' => TRUE, 'any' => FALSE],
            'delete' => ['own' => TRUE, 'any' => FALSE],
          ],
          OgRoleInterface::AUTHENTICATED => [
            'create' => ['any' => TRUE],
            'update' => ['own' => TRUE, 'any' => FALSE],
            'delete' => ['own' => TRUE, 'any' => FALSE],
          ],
        ],
      ],
      [
        'node',
        'article',
        [
          // The super user and the administrator have the right to create,
          // update and delete any article.
          'uid1' => [
            'create' => ['any' => TRUE],
            'update' => ['own' => TRUE, 'any' => TRUE],
            'delete' => ['own' => TRUE, 'any' => TRUE],
          ],
          OgRoleInterface::ADMINISTRATOR => [
            'create' => ['any' => TRUE],
            'update' => ['own' => TRUE, 'any' => TRUE],
            'delete' => ['own' => TRUE, 'any' => TRUE],
          ],
          // Non-members do not have the right to create or manage any article.
          OgRoleInterface::ANONYMOUS => [
            'create' => ['any' => FALSE],
            'update' => ['own' => FALSE, 'any' => FALSE],
            'delete' => ['own' => FALSE, 'any' => FALSE],
          ],
          // Members have the right to create new articles, and to manage their
          // own articles.
          OgRoleInterface::AUTHENTICATED => [
            'create' => ['any' => TRUE],
            'update' => ['own' => TRUE, 'any' => FALSE],
            'delete' => ['own' => TRUE, 'any' => FALSE],
          ],
        ],
      ],
    ];
  }

}