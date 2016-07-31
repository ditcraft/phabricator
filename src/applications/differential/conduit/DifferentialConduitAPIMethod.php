<?php

abstract class DifferentialConduitAPIMethod extends ConduitAPIMethod {

  final public function getApplication() {
    return PhabricatorApplication::getByClass(
      'PhabricatorDifferentialApplication');
  }

  protected function buildDiffInfoDictionary(DifferentialDiff $diff) {
    $uri = '/differential/diff/'.$diff->getID().'/';
    $uri = PhabricatorEnv::getProductionURI($uri);

    return array(
      'id'   => $diff->getID(),
      'phid' => $diff->getPHID(),
      'uri'  => $uri,
    );
  }

  protected function buildInlineInfoDictionary(
    DifferentialInlineComment $inline,
    DifferentialChangeset $changeset = null) {

    $file_path = null;
    $diff_id = null;
    if ($changeset) {
      $file_path = $inline->getIsNewFile()
        ? $changeset->getFilename()
        : $changeset->getOldFile();

      $diff_id = $changeset->getDiffID();
    }

    return array(
      'id'          => $inline->getID(),
      'authorPHID'  => $inline->getAuthorPHID(),
      'filePath'    => $file_path,
      'isNewFile'   => $inline->getIsNewFile(),
      'lineNumber'  => $inline->getLineNumber(),
      'lineLength'  => $inline->getLineLength(),
      'diffID'      => $diff_id,
      'content'     => $inline->getContent(),
    );
  }

  protected function applyFieldEdit(
    ConduitAPIRequest $request,
    DifferentialRevision $revision,
    DifferentialDiff $diff,
    array $fields,
    $message) {

    $viewer = $request->getUser();

    $field_list = PhabricatorCustomField::getObjectFields(
      $revision,
      DifferentialCustomField::ROLE_COMMITMESSAGEEDIT);

    $field_list
      ->setViewer($viewer)
      ->readFieldsFromStorage($revision);
    $field_map = mpull($field_list->getFields(), null, 'getFieldKeyForConduit');

    $xactions = array();

    $xactions[] = id(new DifferentialTransaction())
      ->setTransactionType(DifferentialTransaction::TYPE_UPDATE)
      ->setNewValue($diff->getPHID());

    $values = $request->getValue('fields', array());
    foreach ($values as $key => $value) {
      $field = idx($field_map, $key);
      if (!$field) {
        // NOTE: We're just ignoring fields we don't know about. This isn't
        // ideal, but the way the workflow currently works involves us getting
        // several read-only fields, like the revision ID field, which we should
        // just skip.
        continue;
      }

      $role = PhabricatorCustomField::ROLE_APPLICATIONTRANSACTIONS;
      if (!$field->shouldEnableForRole($role)) {
        continue;
      }

      // TODO: This is fairly similar to PhabricatorCustomField's
      // buildFieldTransactionsFromRequest() method, but that's currently not
      // easy to reuse.

      $transaction_type = $field->getApplicationTransactionType();
      $xaction = id(new DifferentialTransaction())
        ->setTransactionType($transaction_type);

      if ($transaction_type == PhabricatorTransactions::TYPE_CUSTOMFIELD) {
        // For TYPE_CUSTOMFIELD transactions only, we provide the old value
        // as an input.
        $old_value = $field->getOldValueForApplicationTransactions();
        $xaction->setOldValue($old_value);
      }

      // The transaction itself will be validated so this is somewhat
      // redundant, but this validator will sometimes give us a better error
      // message or a better reaction to a bad value type.
      $field->validateCommitMessageValue($value);
      $field->readValueFromCommitMessage($value);

      $xaction
        ->setNewValue($field->getNewValueForApplicationTransactions());

      if ($transaction_type == PhabricatorTransactions::TYPE_CUSTOMFIELD) {
        // For TYPE_CUSTOMFIELD transactions, add the field key in metadata.
        $xaction->setMetadataValue('customfield:key', $field->getFieldKey());
      }

      $metadata = $field->getApplicationTransactionMetadata();
      foreach ($metadata as $meta_key => $meta_value) {
        $xaction->setMetadataValue($meta_key, $meta_value);
      }

      $xactions[] = $xaction;
    }

    $message = $request->getValue('message');
    if (strlen($message)) {
      // This is a little awkward, and should maybe move inside the transaction
      // editor. It largely exists for legacy reasons.
      $first_line = head(phutil_split_lines($message, false));
      $diff->setDescription($first_line);
      $diff->save();

      $xactions[] = id(new DifferentialTransaction())
        ->setTransactionType(PhabricatorTransactions::TYPE_COMMENT)
        ->attachComment(
          id(new DifferentialTransactionComment())
            ->setContent($message));
    }

    $editor = id(new DifferentialTransactionEditor())
      ->setActor($viewer)
      ->setContentSource($request->newContentSource())
      ->setContinueOnNoEffect(true)
      ->setContinueOnMissingFields(true);

    $editor->applyTransactions($revision, $xactions);
  }

  protected function loadCustomFieldsForRevisions(
    PhabricatorUser $viewer,
    array $revisions) {
    assert_instances_of($revisions, 'DifferentialRevision');

    $field_lists = array();
    foreach ($revisions as $revision) {
      $revision_phid = $revision->getPHID();

      $field_list = PhabricatorCustomField::getObjectFields(
        $revision,
        PhabricatorCustomField::ROLE_CONDUIT);

      $field_list
        ->setViewer($viewer)
        ->readFieldsFromObject($revision);

      $field_lists[$revision_phid] = $field_list;
    }

    $all_fields = array();
    foreach ($field_lists as $field_list) {
      foreach ($field_list->getFields() as $field) {
        $all_fields[] = $field;
      }
    }

    id(new PhabricatorCustomFieldStorageQuery())
      ->addFields($all_fields)
      ->execute();

    $results = array();
    foreach ($field_lists as $revision_phid => $field_list) {
      $results[$revision_phid] = array();
      foreach ($field_list->getFields() as $field) {
        $field_key = $field->getFieldKeyForConduit();
        $value = $field->getConduitDictionaryValue();
        $results[$revision_phid][$field_key] = $value;
      }
    }

    // For compatibility, fill in these "custom fields" by querying for them
    // efficiently. See T11404 for discussion.

    $legacy_edge_map = array(
      'phabricator:projects' =>
        PhabricatorProjectObjectHasProjectEdgeType::EDGECONST,
      'phabricator:depends-on' =>
        DifferentialRevisionDependsOnRevisionEdgeType::EDGECONST,
    );

    $query = id(new PhabricatorEdgeQuery())
      ->withSourcePHIDs(array_keys($results))
      ->withEdgeTypes($legacy_edge_map);

    $query->execute();

    foreach ($results as $revision_phid => $dict) {
      foreach ($legacy_edge_map as $edge_key => $edge_type) {
        $phid_list = $query->getDestinationPHIDs(
          array($revision_phid),
          array($edge_type));

        $results[$revision_phid][$edge_key] = $phid_list;
      }
    }

    return $results;
  }

}
