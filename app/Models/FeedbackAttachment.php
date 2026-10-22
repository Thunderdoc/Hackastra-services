<?php

namespace App\Models;

use Eloquent;

class FeedbackAttachment extends Eloquent
{
    protected $table = 'feedback_attachments';

    protected $fillable = ['feedback_id', 'path', 'original_name', 'mime'];

    public function feedback()
    {
        return $this->belongsTo(FeedbackItem::class, 'feedback_id');
    }

    /** Images render as thumbnails; everything else as a file link. */
    public function isImage(): bool
    {
        return $this->mime && strpos($this->mime, 'image/') === 0;
    }

    public function url(): string
    {
        return asset('storage/' . $this->path);
    }
}
