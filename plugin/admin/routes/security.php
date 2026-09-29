<?php

// Species IDs are serialized into comma-separated skill availability lists.
// Reject malformed entries before any write instead of trusting array shape.
function pm_admin_id_list($values)
{
  if (!is_array($values)) {
    throw new InvalidArgumentException('Species IDs must be an array.');
  }
  $ids = [];
  foreach ($values as $value) {
    if ((!is_int($value) && !is_string($value)) || !ctype_digit((string)$value) || intval($value) < 1) {
      throw new InvalidArgumentException('Species IDs must be positive integers.');
    }
    $ids[] = intval($value);
  }
  return $ids;
}
