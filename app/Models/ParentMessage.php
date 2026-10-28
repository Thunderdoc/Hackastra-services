<?php

namespace App\Models;

use App\User;
use Eloquent;

class ParentMessage extends Eloquent
{
    protected $fillable = [
        'sender_id', 'my_class_id', 'section_id', 'title', 'body',
    ];

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function my_class()
    {
        return $this->belongsTo(MyClass::class);
    }

    public function section()
    {
        return $this->belongsTo(Section::class);
    }

    /**
     * Is this message addressed to the given parent user? A parent is
     * addressed when at least one of their children (StudentRecord
     * rows with my_parent_id = the parent) is in the message's class,
     * and — when the message names a section — in that section too.
     * A whole-class message (section_id null) reaches every section
     * of the class.
     */
    public function isAddressedToParent($parentId): bool
    {
        if (!$parentId) {
            return false;
        }

        $query = StudentRecord::where('my_parent_id', (int) $parentId)
            ->where('my_class_id', (int) $this->my_class_id);

        if ($this->section_id !== null) {
            // Section-specific message: only a child recorded in
            // exactly that section is addressed.
            $query->where('section_id', (int) $this->section_id);
        }

        return $query->exists();
    }

    /**
     * Query scope: messages addressed to a parent, by the same rule
     * as isAddressedToParent (class match, plus section match when
     * the message has a section).
     */
    public function scopeAddressedToParent($query, $parentId)
    {
        $children = StudentRecord::where('my_parent_id', (int) $parentId)
            ->get(['my_class_id', 'section_id']);

        return $query->where(function ($q) use ($children) {
            foreach ($children as $child) {
                $q->orWhere(function ($w) use ($child) {
                    $w->where('my_class_id', (int) $child->my_class_id);
                    if ($child->section_id) {
                        $w->where(function ($s) use ($child) {
                            $s->whereNull('section_id')
                                ->orWhere('section_id', (int) $child->section_id);
                        });
                    } else {
                        // Child with no recorded section: only
                        // whole-class messages can be proven to
                        // reach them.
                        $w->whereNull('section_id');
                    }
                });
            }
            if ($children->isEmpty()) {
                $q->whereRaw('1 = 0');
            }
        });
    }
}
