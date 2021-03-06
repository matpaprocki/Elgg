<?php
/**
 * Elgg Annotations
 *
 * Annotations allow you to attach bits of information to entities.
 * They are essentially the same as metadata, but with additional
 * helper functions.
 *
 * @internal Annotations are stored in the annotations table.
 *
 * @package    Elgg.Core
 * @subpackage DataModel.Annotations
 * @link       http://docs.elgg.org/DataModel/Annotations
 *
 * @property string $value_type
 * @property string $enabled
 */
class ElggAnnotation extends ElggExtender {

	/**
	 * (non-PHPdoc)
	 *
	 * @see ElggData::initializeAttributes()
	 *
	 * @return void
	 */
	protected function initializeAttributes() {
		parent::initializeAttributes();

		$this->attributes['type'] = 'annotation';
	}

	/**
	 * Construct a new annotation object
	 *
	 * @param mixed $id The annotation ID or a database row as stdClass object
	 */
	function __construct($id = null) {
		$this->initializeAttributes();

		if (!empty($id)) {
			// Create from db row
			if ($id instanceof stdClass) {
				$annotation = $id;

				$objarray = (array) $annotation;
				foreach ($objarray as $key => $value) {
					$this->attributes[$key] = $value;
				}
			} else {
				// get an ElggAnnotation object and copy its attributes
				$annotation = elgg_get_annotation_from_id($id);
				$this->attributes = $annotation->attributes;
			}
		}
	}

	/**
	 * Save this instance
	 *
	 * @return int an object id
	 *
	 * @throws IOException
	 */
	function save() {
		if ($this->id > 0) {
			return update_annotation($this->id, $this->name, $this->value, $this->value_type,
				$this->owner_guid, $this->access_id);
		} else {
			$this->id = create_annotation($this->entity_guid, $this->name, $this->value,
				$this->value_type, $this->owner_guid, $this->access_id);

			if (!$this->id) {
				throw new IOException("Unable to save new " . get_class());
			}
			return $this->id;
		}
	}

	/**
	 * Delete the annotation.
	 *
	 * @return bool
	 */
	function delete() {
		$result = _elgg_delete_metastring_based_object_by_id($this->id, 'annotations');
		if ($result) {
			elgg_delete_river(array('annotation_id' => $this->id));
		}

		return $result;
	}

	/**
	 * Disable the annotation.
	 *
	 * @return bool
	 * @since 1.8
	 */
	function disable() {
		return _elgg_set_metastring_based_object_enabled_by_id($this->id, 'no', 'annotations');
	}

	/**
	 * Enable the annotation.
	 *
	 * @return bool
	 * @since 1.8
	 */
	function enable() {
		return _elgg_set_metastring_based_object_enabled_by_id($this->id, 'yes', 'annotations');
	}

	// SYSTEM LOG INTERFACE

	/**
	 * For a given ID, return the object associated with it.
	 * This is used by the river functionality primarily.
	 * This is useful for checking access permissions etc on objects.
	 *
	 * @param int $id An annotation ID.
	 *
	 * @return ElggAnnotation
	 */
	public function getObjectFromID($id) {
		return elgg_get_annotation_from_id($id);
	}
}
