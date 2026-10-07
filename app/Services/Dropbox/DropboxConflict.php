<?php

namespace App\Services\Dropbox;

/** Something already exists at the target path. Nothing was written. */
class DropboxConflict extends DropboxException {}
