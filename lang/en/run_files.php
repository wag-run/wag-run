<?php

return [
    'cannot_be_part_of_text' => 'A file cannot be part of text. Use it as the whole value, such as {{ nodes.fetch.output.file }}, or read it with text() or base64().',
    'file_is_missing' => 'The file ":name" is missing from storage.',
    'download' => 'Download',
    'file_not_found' => 'That file was not found in this workspace.',
    'files' => 'Files',
    'name' => 'Name',
    'not_a_file' => 'That value is not a file.',
    'not_text' => 'That file is not text. Use base64() to read its bytes.',
    'size_in_bytes' => 'Size in bytes',
    'too_large' => 'The file is larger than the :limit limit.',
    'too_large_to_read' => 'The file ":name" is larger than :limit, too large to read into the run\'s data.',
    'type' => 'Type',
];
