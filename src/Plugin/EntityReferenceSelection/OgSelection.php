<?php

/**
 * @file
 * Contains \Drupal\node\Plugin\EntityReferenceSelection\OgSelection.
 */

namespace Drupal\og\Plugin\EntityReferenceSelection;

use Drupal\Core\Entity\Plugin\EntityReferenceSelection\SelectionBase;
use Drupal\og\Controller\OG;

/**
 * Provide default OG selection handler.
 *
 * @EntityReferenceSelection(
 *   id = "default:og",
 *   label = @Translation("OG selection"),
 *   entity_types = {"node"},
 *   group = "default",
 *   weight = 1
 * )
 */
class OgSelection extends SelectionBase {

  /**
   * Overrides the basic entity query object. Return only group in the matching
   * results.
   *
   * @param string|null $match
   *   (Optional) Text to match the label against. Defaults to NULL.
   * @param string $match_operator
   *   (Optional) The operation the matching should be done with. Defaults
   *   to "CONTAINS".
   *
   * @return \Drupal\Core\Entity\Query\QueryInterface
   *   The EntityQuery object with the basic conditions and sorting applied to
   *   it.
   */
  protected function buildEntityQuery($match = NULL, $match_operator = 'CONTAINS') {
    $query = parent::buildEntityQuery($match, $match_operator);
    $query->condition(OG_GROUP_FIELD, 1);

    if ($this->configuration['handler_settings']['other_groups']) {
      $ids = [];
      $settings = $this->configuration['handler_settings'];
      $other_groups = OG::getEntityGroups('user', NULL, [OG_STATE_ACTIVE], $settings['field_name']);

      foreach ($other_groups[$settings['entity_type']] as $group) {
        $ids[] = $group->id();
      }

      $query->condition(\Drupal::entityManager()->getDefinition($settings['entity_type'])->getKey('id'), $ids, 'IN');
    }

    return $query;
  }

}