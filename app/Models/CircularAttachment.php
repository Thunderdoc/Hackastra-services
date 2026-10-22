<?php

namespace App\Models;

use Eloquent;

class CircularAttachment extends Eloquent
{
    protected $table = 'circular_attachments';

    protected $fillable = ['circular_id', 'path', 'original_name', 'mime'];

    public function circular()
    {
        return $this->belongsTo(Circular::class, 'circular_id');
    }

    /** Images render as thumbnails; everything else as a file link. */
    public function isImage(): bool
    {
        return $this->mime && strpos($this->mime, 'image/') === 0;
    }

    /**
     * The gated download URL — deliberately NOT a public storage
     * URL: the controller re-checks the circular's audience for
     * every download, exactly like the circular page itself.
     */
    public function downloadUrl(): string
    {
        return route('circulars.attachments.download', [$this->circular_id, $this->id]);
    }
}
