<?php
/**
 * Elgg relationships.
 *
 * @package    Elgg.Core
 * @subpackage DataModel.Relationship
 */

/**
 * Convert a database row to a new ElggRelationship
 *
 * @param stdClass $row Database row from the relationship table
 *
 * @return ElggRelationship|stdClass
 * @access private
 */
function row_to_elggrelationship($row) {
	if (!($row instanceof stdClass)) {
		return $row;
	}

	return new ElggRelationship($row);
}

/**
 * Return a relationship.
 *
 * @param int $id The ID of a relationship
 *
 * @return ElggRelationship|false
 */
function get_relationship($id) {
	global $CONFIG;

	$id = (int)$id;

	$query = "SELECT * from {$CONFIG->dbprefix}entity_relationships where id=$id";
	return row_to_elggrelationship(get_data_row($query));
}

/**
 * Delete a specific relationship.
 *
 * @param int $id The relationship ID
 *
 * @return bool
 */
function delete_relationship($id) {
	global $CONFIG;

	$id = (int)$id;

	$relationship = get_relationship($id);

	if (elgg_trigger_event('delete', 'relationship', $relationship)) {
		return delete_data("DELETE FROM {$CONFIG->dbprefix}entity_relationships WHERE id = $id");
	}

	return false;
}

/**
 * Define an arbitrary relationship between two entities.
 * This relationship could be a friendship, a group membership or a site membership.
 *
 * This function lets you make the statement "$guid_one is a $relationship of $guid_two".
 *
 * @param int    $guid_one     First GUID
 * @param string $relationship Relationship name
 * @param int    $guid_two     Second GUID
 *
 * @return bool
 * @throws InvalidArgumentException
 */
function add_entity_relationship($guid_one, $relationship, $guid_two) {
	global $CONFIG;

	if (strlen($relationship) > ElggRelationship::RELATIONSHIP_LIMIT) {
		$msg = "relationship name cannot be longer than " . ElggRelationship::RELATIONSHIP_LIMIT;
		throw InvalidArgumentException($msg);
	}

	$guid_one = (int)$guid_one;
	$relationship = sanitise_string($relationship);
	$guid_two = (int)$guid_two;
	$time = time();

	// Check for duplicates
	if (check_entity_relationship($guid_one, $relationship, $guid_two)) {
		return false;
	}

	$id = insert_data("INSERT INTO {$CONFIG->dbprefix}entity_relationships
		(guid_one, relationship, guid_two, time_created)
		VALUES ($guid_one, '$relationship', $guid_two, $time)");

	if ($id !== false) {
		$obj = get_relationship($id);

		// this event has been deprecated in 1.9. Use 'create', 'relationship'
		$result_old = elgg_trigger_event('create', $relationship, $obj);

		$result = elgg_trigger_event('create', 'relationship', $obj);
		if ($result && $result_old) {
			return true;
		} else {
			delete_relationship($result);
		}
	}

	return false;
}

/**
 * Determine if a relationship between two entities exists
 * and returns the relationship object if it does
 *
 * @param int    $guid_one     The GUID of the entity "owning" the relationship
 * @param string $relationship The type of relationship
 * @param int    $guid_two     The GUID of the entity the relationship is with
 *
 * @return ElggRelationship|false Depending on success
 */
function check_entity_relationship($guid_one, $relationship, $guid_two) {
	global $CONFIG;

	$guid_one = (int)$guid_one;
	$relationship = sanitise_string($relationship);
	$guid_two = (int)$guid_two;

	$query = "SELECT * FROM {$CONFIG->dbprefix}entity_relationships
		WHERE guid_one=$guid_one
			AND relationship='$relationship'
			AND guid_two=$guid_two limit 1";

	$row = row_to_elggrelationship(get_data_row($query));
	if ($row) {
		return $row;
	}

	return false;
}

/**
 * Remove an arbitrary relationship between two entities.
 *
 * @param int    $guid_one     First GUID
 * @param string $relationship Relationship name
 * @param int    $guid_two     Second GUID
 *
 * @return bool
 */
function remove_entity_relationship($guid_one, $relationship, $guid_two) {
	global $CONFIG;

	$guid_one = (int)$guid_one;
	$relationship = sanitise_string($relationship);
	$guid_two = (int)$guid_two;

	$obj = check_entity_relationship($guid_one, $relationship, $guid_two);
	if ($obj == false) {
		return false;
	}

	// this event has been deprecated in 1.9. Use 'delete', 'relationship'
	$result_old = elgg_trigger_event('delete', $relationship, $obj);

	$result = elgg_trigger_event('delete', 'relationship', $obj);
	if ($result && $result_old) {
		$query = "DELETE FROM {$CONFIG->dbprefix}entity_relationships
			WHERE guid_one = $guid_one
			AND relationship = '$relationship'
			AND guid_two = $guid_two";

		return (bool)delete_data($query);
	} else {
		return false;
	}
}

/**
 * Removes all arbitrary relationships originating from a particular entity
 *
 * @param int    $guid_one     The GUID of the entity
 * @param string $relationship The name of the relationship (optional)
 * @param bool   $inverse      Whether we're deleting inverse relationships (default false)
 * @param string $type         The type of entity to the delete to (defaults to all)
 *
 * @return bool Depending on success
 */
function remove_entity_relationships($guid_one, $relationship = "", $inverse = false, $type = '') {
	global $CONFIG;

	$guid_one = (int) $guid_one;

	if (!empty($relationship)) {
		$relationship = sanitise_string($relationship);
		$where = "and er.relationship='$relationship'";
	} else {
		$where = "";
	}

	if (!empty($type)) {
		$type = sanitise_string($type);
		if (!$inverse) {
			$join = " join {$CONFIG->dbprefix}entities e on e.guid = er.guid_two ";
		} else {
			$join = " join {$CONFIG->dbprefix}entities e on e.guid = er.guid_one ";
			$where .= " and ";
		}
		$where .= " and e.type = '{$type}' ";
	} else {
		$join = "";
	}

	if (!$inverse) {
		$sql = "DELETE er from {$CONFIG->dbprefix}entity_relationships as er
			{$join}
			where guid_one={$guid_one} {$where}";

		return delete_data($sql);
	} else {
		$sql = "DELETE er from {$CONFIG->dbprefix}entity_relationships as er
			{$join} where
			guid_two={$guid_one} {$where}";

		return delete_data($sql);
	}
}

/**
 * Get all the relationships for a given guid.
 *
 * @param int  $guid                 The GUID of the relationship owner
 * @param bool $inverse_relationship Inverse relationship owners?
 *
 * @return ElggRelationship[]
 */
function get_entity_relationships($guid, $inverse_relationship = false) {
	global $CONFIG;

	$guid = (int)$guid;

	$where = ($inverse_relationship ? "guid_two='$guid'" : "guid_one='$guid'");

	$query = "SELECT * from {$CONFIG->dbprefix}entity_relationships where {$where}";

	return get_data($query, "row_to_elggrelationship");
}

/**
 * Return entities matching a given query joining against a relationship.
 * Also accepts all options available to elgg_get_entities() and
 * elgg_get_entities_from_metadata().
 *
 * To ask for entities that do not have a particular relationship to an entity,
 * use a custom where clause like the following:
 *
 * 	$options['wheres'][] = "NOT EXISTS (
 *			SELECT 1 FROM {$db_prefix}entity_relationships
 *				WHERE guid_one = e.guid
 *				AND relationship = '$relationship'
 *		)";
 *
 * @see elgg_get_entities
 * @see elgg_get_entities_from_metadata
 *
 * @param array $options Array in format:
 *
 * 	relationship => null|STR relationship
 *
 * 	relationship_guid => null|INT Guid of relationship to test
 *
 * 	inverse_relationship => BOOL Inverse the relationship
 * 
 *  relationship_join_on => null|STR How the entities relate: guid (default), container_guid, or owner_guid
 *                          Add in Elgg 1.9.0. Examples using the relationship 'friend':
 *                          1. use 'guid' if you want the user's friends
 *                          2. use 'owner_guid' if you want the entities the user's friends own (including in groups)
 *                          3. use 'container_guid' if you want the entities in the user's personal space (non-group)
 *
 * @return ElggEntity[]|mixed If count, int. If not count, array. false on errors.
 * @since 1.7.0
 */
function elgg_get_entities_from_relationship($options) {
	$defaults = array(
		'relationship' => null,
		'relationship_guid' => null,
		'inverse_relationship' => false,
		'relationship_join_on' => 'guid',
	);

	$options = array_merge($defaults, $options);

	$join_column = "e.{$options['relationship_join_on']}";
	$clauses = elgg_get_entity_relationship_where_sql($join_column, $options['relationship'],
		$options['relationship_guid'], $options['inverse_relationship']);

	if ($clauses) {
		// merge wheres to pass to get_entities()
		if (isset($options['wheres']) && !is_array($options['wheres'])) {
			$options['wheres'] = array($options['wheres']);
		} elseif (!isset($options['wheres'])) {
			$options['wheres'] = array();
		}

		$options['wheres'] = array_merge($options['wheres'], $clauses['wheres']);

		// merge joins to pass to get_entities()
		if (isset($options['joins']) && !is_array($options['joins'])) {
			$options['joins'] = array($options['joins']);
		} elseif (!isset($options['joins'])) {
			$options['joins'] = array();
		}

		$options['joins'] = array_merge($options['joins'], $clauses['joins']);

		if (isset($options['selects']) && !is_array($options['selects'])) {
			$options['selects'] = array($options['selects']);
		} elseif (!isset($options['selects'])) {
			$options['selects'] = array();
		}

		$select = array('r.id');

		$options['selects'] = array_merge($options['selects'], $select);
	}

	return elgg_get_entities_from_metadata($options);
}

/**
 * Returns sql appropriate for relationship joins and wheres
 *
 * @todo add support for multiple relationships and guids.
 *
 * @param string $column               Column name the guid should be checked against.
 *                                     Provide in table.column format.
 * @param string $relationship         Relationship string
 * @param int    $relationship_guid    Entity guid to check
 * @param bool $inverse_relationship Inverse relationship check?
 *
 * @return mixed
 * @since 1.7.0
 * @access private
 */
function elgg_get_entity_relationship_where_sql($column, $relationship = null,
$relationship_guid = null, $inverse_relationship = false) {

	if ($relationship == null && $relationship_guid == null) {
		return '';
	}

	global $CONFIG;

	$wheres = array();
	$joins = array();

	if ($inverse_relationship) {
		$joins[] = "JOIN {$CONFIG->dbprefix}entity_relationships r on r.guid_one = $column";
	} else {
		$joins[] = "JOIN {$CONFIG->dbprefix}entity_relationships r on r.guid_two = $column";
	}

	if ($relationship) {
		$wheres[] = "r.relationship = '" . sanitise_string($relationship) . "'";
	}

	if ($relationship_guid) {
		if ($inverse_relationship) {
			$wheres[] = "r.guid_two = '$relationship_guid'";
		} else {
			$wheres[] = "r.guid_one = '$relationship_guid'";
		}
	}

	if ($where_str = implode(' AND ', $wheres)) {

		return array('wheres' => array("($where_str)"), 'joins' => $joins);
	}

	return '';
}

/**
 * Returns a viewable list of entities by relationship
 *
 * @param array $options Options array for retrieval of entities
 *
 * @see elgg_list_entities()
 * @see elgg_get_entities_from_relationship()
 *
 * @return string The viewable list of entities
 */
function elgg_list_entities_from_relationship(array $options = array()) {
	return elgg_list_entities($options, 'elgg_get_entities_from_relationship');
}

/**
 * Gets the number of entities by a the number of entities related to them in a particular way.
 * This is a good way to get out the users with the most friends, or the groups with the
 * most members.
 *
 * @param array $options An options array compatible with
 *                       elgg_get_entities_from_relationship()
 * @return ElggEntity[]|int|boolean If count, int. If not count, array. false on errors.
 * @since 1.8.0
 */
function elgg_get_entities_from_relationship_count(array $options = array()) {
	$options['selects'][] = "COUNT(e.guid) as total";
	$options['group_by'] = 'r.guid_two';
	$options['order_by'] = 'total desc';
	return elgg_get_entities_from_relationship($options);
}

/**
 * Returns a list of entities by relationship count
 *
 * @see elgg_get_entities_from_relationship_count()
 *
 * @param array $options Options array
 *
 * @return string
 * @since 1.8.0
 */
function elgg_list_entities_from_relationship_count($options) {
	return elgg_list_entities($options, 'elgg_get_entities_from_relationship_count');
}

/**
 * Notify user that someone has friended them
 *
 * @param string           $event  Event name
 * @param string           $type   Object type
 * @param ElggRelationship $object Object
 *
 * @return bool
 * @access private
 */
function relationship_notification_hook($event, $type, $object) {
	$user_one = get_entity($object->guid_one);
	/* @var ElggUser $user_one */

	return notify_user($object->guid_two,
			$object->guid_one,
			elgg_echo('friend:newfriend:subject', array($user_one->name)),
			elgg_echo("friend:newfriend:body", array($user_one->name, $user_one->getURL()))
	);
}

// Register event to listen to some events
elgg_register_event_handler('create', 'friend', 'relationship_notification_hook');
