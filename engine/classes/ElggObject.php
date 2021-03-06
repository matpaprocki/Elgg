<?php
/**
 * Elgg Object
 *
 * Elgg objects are the most common means of storing information in the database.
 * They are a child class of ElggEntity, so receive all the benefits of the Entities,
 * but also include a title and description field.
 *
 * An ElggObject represents a row from the objects_entity table, as well
 * as the related row in the entities table as represented by the parent
 * ElggEntity object.
 *
 * @internal Title and description are stored in the objects_entity table.
 *
 * @package    Elgg.Core
 * @subpackage DataModel.Object
 * 
 * @property string $title       The title, name, or summary of this object
 * @property string $description The body, description, or content of the object
 * @property array  $tags        Array of tags that describe the object
 */
class ElggObject extends ElggEntity {

	/**
	 * Initialise the attributes array to include the type,
	 * title, and description.
	 *
	 * @return void
	 */
	protected function initializeAttributes() {
		parent::initializeAttributes();

		$this->attributes['type'] = "object";
		$this->attributes['title'] = null;
		$this->attributes['description'] = null;
		$this->attributes['tables_split'] = 2;
	}

	/**
	 * Load or create a new ElggObject.
	 *
	 * If no arguments are passed, create a new entity.
	 *
	 * If an argument is passed, attempt to load a full ElggObject entity.
	 * Arguments can be:
	 *  - The GUID of an object entity.
	 *  - A DB result object from the entities table with a guid property
	 *
	 * @param mixed $guid If an int, load that GUID.  If a db row, then will attempt to
	 * load the rest of the data.
	 *
	 * @throws IOException If passed an incorrect guid
	 * @throws InvalidParameterException If passed an Elgg* Entity that isn't an ElggObject
	 */
	function __construct($guid = null) {
		$this->initializeAttributes();

		// compatibility for 1.7 api.
		$this->initialise_attributes(false);

		if (!empty($guid)) {
			// Is $guid is a DB row from the entity table
			if ($guid instanceof stdClass) {
				// Load the rest
				if (!$this->load($guid)) {
					$msg = "Failed to load new " . get_class() . " from GUID:" . $guid->guid;
					throw new IOException($msg);
				}
			} else if ($guid instanceof ElggObject) {
				// $guid is an ElggObject so this is a copy constructor
				elgg_deprecated_notice('This type of usage of the ElggObject constructor was deprecated. Please use the clone method.', 1.7);

				foreach ($guid->attributes as $key => $value) {
					$this->attributes[$key] = $value;
				}
			} else if ($guid instanceof ElggEntity) {
				// @todo remove - do not need separate exception
				throw new InvalidParameterException("Passing a non-ElggObject to an ElggObject constructor!");
			} else if (is_numeric($guid)) {
				// $guid is a GUID so load
				if (!$this->load($guid)) {
					throw new IOException("Failed to load new " . get_class() . " from GUID:" . $guid);
				}
			} else {
				throw new InvalidParameterException("Unrecognized value passed to constuctor.");
			}
		}
	}

	/**
	 * Loads the full ElggObject when given a guid.
	 *
	 * @param mixed $guid GUID of an ElggObject or the stdClass object from entities table
	 *
	 * @return bool
	 * @throws InvalidClassException
	 */
	protected function load($guid) {
		$attr_loader = new Elgg_AttributeLoader(get_class(), 'object', $this->attributes);
		$attr_loader->requires_access_control = !($this instanceof ElggPlugin);
		$attr_loader->secondary_loader = 'get_object_entity_as_row';

		$attrs = $attr_loader->getRequiredAttributes($guid);
		if (!$attrs) {
			return false;
		}

		$this->attributes = $attrs;
		$this->attributes['tables_loaded'] = 2;
		_elgg_cache_entity($this);

		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function create() {
		global $CONFIG;

		$guid = parent::create();
		$title = sanitize_string($this->title);
		$description = sanitize_string($this->description);
		
		$query = "INSERT into {$CONFIG->dbprefix}objects_entity
			(guid, title, description) values ($guid, '$title', '$description')";

		$result = $this->getDatabase()->insertData($query);
		if ($result === false) {
			// TODO(evan): Throw an exception here?
			return false;
		}
		
		return $guid;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function update() {
		global $CONFIG;

		if (!parent::update()) {
			return false;
		}
		
		$guid = (int)$this->guid;
		$title = sanitize_string($this->title);
		$description = sanitize_string($this->description);

		$query = "UPDATE {$CONFIG->dbprefix}objects_entity
			set title='$title', description='$description' where guid=$guid";

		return $this->getDatabase()->updateData($query) !== false;
	}


	/**
	 * {@inheritdoc}
	 */
	public function getDisplayName() {
		return $this->title;
	}

	/**
	 * {@inheritdoc}
	 */
	public function setDisplayName($displayName) {
		$this->title = $displayName;
	}

	/**
	 * Return sites that this object is a member of
	 *
	 * Site membership is determined by relationships and not site_guid.
	 *
	 * @todo This should be moved to ElggEntity
	 *
	 * @param string $subtype Optionally, the subtype of result we want to limit to
	 * @param int    $limit   The number of results to return
	 * @param int    $offset  Any indexing offset
	 *
	 * @return array
	 */
	function getSites($subtype = "", $limit = 10, $offset = 0) {
		return get_site_objects($this->getGUID(), $subtype, $limit, $offset);
	}

	/**
	 * Add this object to a site
	 *
	 * @param int $site_guid The guid of the site to add it to
	 *
	 * @return bool
	 */
	function addToSite($site_guid) {
		return add_site_object($this->getGUID(), $site_guid);
	}

	/**
	 * {@inheritdoc}
	 */
	protected function prepareObject($object) {
		$object = parent::prepareObject($object);
		$object->title = $this->getDisplayName();
		$object->description = $this->description;
		$object->tags = $this->tags ? $this->tags : array();
		return $object;
	}

	/*
	 * EXPORTABLE INTERFACE
	 */

	/**
	 * Return an array of fields which can be exported.
	 *
	 * @return array
	 * @deprecated 1.9 Use toObject()
	 */
	public function getExportableValues() {
		return array_merge(parent::getExportableValues(), array(
			'title',
			'description',
		));
	}

	/**
	 * Can a user comment on this object?
	 *
	 * @see ElggEntity::canComment()
	 *
	 * @param int $user_guid User guid (default is logged in user)
	 * @return bool
	 * @since 1.8.0
	 */
	public function canComment($user_guid = 0) {
		$result = parent::canComment($user_guid);
		if ($result !== null) {
			return $result;
		}

		if ($user_guid == 0) {
			$user_guid = elgg_get_logged_in_user_guid();
		}

		// must be logged in to comment
		if (!$user_guid) {
			return false;
		}

		// must be member of group
		if (elgg_instanceof($this->getContainerEntity(), 'group')) {
			if (!$this->getContainerEntity()->canWriteToContainer($user_guid)) {
				return false;
			}
		}

		// no checks on read access since a user cannot see entities outside his access
		return true;
	}
}
