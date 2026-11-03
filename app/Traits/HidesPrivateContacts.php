<?php

namespace App\Traits;

use Horsefly\JobSource;
use Horsefly\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

trait HidesPrivateContacts
{
    protected $privateDataContextCache = false;

    /**
     * Exclude contacts hidden by hide_private_data for users without show-private-data.
     */
    protected function excludePrivateContacts($query)
    {
        $context = $this->privateDataContext();

        if ($context === null) {
            return $query;
        }

        return $query->where(function ($q) use ($context) {
            foreach ($context['values'] as $hideValue) {
                $q->where(function ($fieldGroup) use ($hideValue) {
                    $fieldGroup
                        ->where(function ($nameCheck) use ($hideValue) {
                            $nameCheck->whereNull('contacts.contact_name')
                                ->orWhere('contacts.contact_name', 'NOT LIKE', '%' . $hideValue . '%');
                        })
                        ->where(function ($emailCheck) use ($hideValue) {
                            $emailCheck->whereNull('contacts.contact_email')
                                ->orWhere('contacts.contact_email', 'NOT LIKE', '%' . $hideValue . '%');
                        })
                        ->where(function ($noteCheck) use ($hideValue) {
                            $noteCheck->whereNull('contacts.contact_note')
                                ->orWhere('contacts.contact_note', 'NOT LIKE', '%' . $hideValue . '%');
                        });
                });
            }

            if ($context['sourceIds'] !== []) {
                $q->where(function ($source) use ($context) {
                    $source->whereNotIn('contacts.job_source_id', $context['sourceIds'])
                        ->orWhereNull('contacts.job_source_id');
                });
            }
        });
    }

    /**
     * Join only visible unit contacts, using a string compare so the contactable_id index is used.
     * Private contacts (hide_private_data / job source) are excluded in SQL so the export does not
     * hydrate them in PHP.
     */
    protected function constrainVisibleUnitContacts($join): void
    {
        $join->on('contacts.contactable_id', '=', DB::raw('CAST(units.id AS CHAR)'))
            ->where('contacts.contactable_type', '=', 'Horsefly\\Unit');

        $context = $this->privateDataContext();
        if ($context === null) {
            return;
        }

        foreach ($context['values'] as $hideValue) {
            $like = '%' . $hideValue . '%';
            $join->whereRaw('(contacts.contact_name IS NULL OR contacts.contact_name NOT LIKE ?)', [$like]);
            $join->whereRaw('(contacts.contact_email IS NULL OR contacts.contact_email NOT LIKE ?)', [$like]);
            $join->whereRaw('(contacts.contact_note IS NULL OR contacts.contact_note NOT LIKE ?)', [$like]);
        }

        if ($context['sourceIds'] !== []) {
            $placeholders = implode(',', array_fill(0, count($context['sourceIds']), '?'));
            $join->whereRaw(
                "(contacts.job_source_id IS NULL OR contacts.job_source_id NOT IN ({$placeholders}))",
                $context['sourceIds']
            );
        }
    }

    /**
     * for users without show-private-data. Numeric values in the setting are job source
     * ids, not words, so they are not matched against notes.
     */
    protected function excludePrivateNotes($query, $notesColumn)
    {
        // $notesColumn may be a column name or a DB::raw() expression.
        foreach ($this->privateNoteLikePatterns() as $like) {
            $query->where(function ($q) use ($notesColumn, $like) {
                $q->whereNull($notesColumn)->orWhere($notesColumn, 'NOT LIKE', $like);
            });
        }

        return $query;
    }

    /**
     * Same as excludePrivateNotes(), for the column a query selects under an alias
     * (e.g. "crm_notes.details as notes_detail"). Does nothing if no such alias is selected.
     */
    protected function excludePrivateSelectedNotes($query, string $alias = 'notes_detail')
    {
        $base = method_exists($query, 'getQuery') ? $query->getQuery() : $query;

        $column = collect($base->columns ?? [])
            ->filter(fn ($column) => is_string($column))
            ->map(fn ($column) => preg_match('/^(\S+)\s+as\s+' . preg_quote($alias, '/') . '$/i', trim($column), $m) ? $m[1] : null)
            ->filter()
            ->first();

        return $column ? $this->excludePrivateNotes($query, $column) : $query;
    }

    /**
     * LIKE patterns for the hide_private_data words, or [] when the user may see private data.
     * Numeric values are job source ids, not words, so they are skipped.
     */
    protected function privateNoteLikePatterns(): array
    {
        $context = $this->privateDataContext();

        if ($context === null) {
            return [];
        }

        return collect($context['values'])
            ->reject(fn ($value) => ctype_digit((string) $value))
            ->map(fn ($value) => '%' . addcslashes($value, '\\%_') . '%')
            ->values()
            ->all();
    }

    /**
     * Hide rows whose joined office or unit notes contain a hide_private_data word.
     * Only filters tables the query actually joins as `offices` / `units`, so it is safe
     * to call on any query; with a left join, rows without an office/unit stay visible.
     */
    protected function excludePrivateOfficeUnitNotes($query)
    {
        if ($this->privateDataContext() === null) {
            return $query;
        }

        $base = method_exists($query, 'getQuery') ? $query->getQuery() : $query;
        $joinedTables = collect($base->joins ?? [])->pluck('table');

        if ($joinedTables->contains('offices')) {
            $this->excludePrivateNotes($query, 'offices.office_notes');
        }
        if ($joinedTables->contains('units')) {
            $this->excludePrivateNotes($query, 'units.unit_notes');
        }

        return $query;
    }

    /**
     * Hide rows that belong to a sale whose office or unit notes contain a hide_private_data
     * word (the same sales the CRM, quality and history pages hide). $saleIdColumn is the
     * column holding the sale id, e.g. 'sales.id', 'history.sale_id' or 'audits.auditable_id'.
     * Rows without a sale id are kept.
     */
    protected function excludePrivateSales($query, string $saleIdColumn)
    {
        $patterns = $this->privateNoteLikePatterns();

        if ($patterns === []) {
            return $query;
        }

        return $query->where(function ($outer) use ($saleIdColumn, $patterns) {
            $outer->whereNull($saleIdColumn)
                ->orWhereNotIn($saleIdColumn, function ($sub) use ($patterns) {
                    $sub->select('private_sales.id')
                        ->from('sales as private_sales')
                        ->leftJoin('offices as private_offices', 'private_offices.id', '=', 'private_sales.office_id')
                        ->leftJoin('units as private_units', 'private_units.id', '=', 'private_sales.unit_id')
                        ->where(function ($match) use ($patterns) {
                            foreach ($patterns as $like) {
                                $match->orWhere('private_offices.office_notes', 'LIKE', $like)
                                    ->orWhere('private_units.unit_notes', 'LIKE', $like);
                            }
                        });
                });
        });
    }

    /**
     * Hide sales whose job_source_id matches hide_private_data (same as the sales list).
     */
    protected function excludeHiddenSaleSources($query)
    {
        $context = $this->privateDataContext();

        if ($context === null || $context['sourceIds'] === []) {
            return $query;
        }

        return $query->where(function ($q) use ($context) {
            $q->whereNotIn('sales.job_source_id', $context['sourceIds'])
                ->orWhereNull('sales.job_source_id');
        });
    }

    /**
     * Blank contact fields when the joined contact is private.
     */
    protected function hidePrivateContactData($item)
    {
        if (!$this->isPrivateContactRow($item)) {
            return $item;
        }

        $item->contact_name = null;
        $item->contact_email = null;
        $item->contact_phone = null;
        $item->contact_landline = null;
        $item->contact_note = null;

        return $item;
    }

    protected function isPrivateContactRow($item): bool
    {
        $context = $this->privateDataContext();

        if ($context === null) {
            return false;
        }

        $contactSourceId = $item->contact_job_source_id ?? null;
        if ($contactSourceId !== null && $contactSourceId !== '' && $context['sourceIds'] !== []) {
            if (in_array((int) $contactSourceId, $context['sourceIds'], true)) {
                return true;
            }
        }

        foreach ($context['values'] as $hideValue) {
            foreach ([$item->contact_email ?? null, $item->contact_name ?? null, $item->contact_note ?? null] as $haystack) {
                if ($haystack !== null && $haystack !== '' && stripos((string) $haystack, $hideValue) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Job source IDs hidden by hide_private_data. Empty when the user may see private data.
     */
    protected function hiddenPrivateSourceIds(): array
    {
        $context = $this->privateDataContext();

        return $context === null ? [] : $context['sourceIds'];
    }

    /**
     * @return array{values: array, sourceIds: array}|null
     */
    protected function privateDataContext(): ?array
    {
        if ($this->privateDataContextCache !== false) {
            return $this->privateDataContextCache;
        }

        if (Gate::allows('show-private-data')) {
            return $this->privateDataContextCache = null;
        }

        $values = array_values(array_filter(
            array_map('trim', explode(',', Setting::where('key', 'hide_private_data')->value('value') ?? ''))
        ));

        if ($values === []) {
            return $this->privateDataContextCache = null;
        }

        $sourceIds = JobSource::query()
            ->where(function ($q) use ($values) {
                foreach ($values as $hideName) {
                    $q->orWhere('name', 'LIKE', '%' . $hideName . '%');
                    if (ctype_digit((string) $hideName)) {
                        $q->orWhere('id', (int) $hideName);
                    }
                }
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($values as $hideName) {
            if (ctype_digit((string) $hideName)) {
                $sourceIds[] = (int) $hideName;
            }
        }

        return $this->privateDataContextCache = [
            'values' => $values,
            'sourceIds' => array_values(array_unique($sourceIds)),
        ];
    }
}
