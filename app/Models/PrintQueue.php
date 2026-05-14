<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class PrintQueue extends Model
{
    use HasFactory;

    protected $table = 'print_queue';

    protected $fillable = [
        'printer_id',
        'job_type',
        'content',
        'status',
        'priority',
        'retry_count',
        'max_retries',
        'last_error',
        'next_retry_at',
        'completed_at',
    ];

    protected $casts = [
        'content' => 'array',
        'priority' => 'integer',
        'retry_count' => 'integer',
        'max_retries' => 'integer',
        'next_retry_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Get the printer that owns the print job
     */
    public function printer()
    {
        return $this->belongsTo(Printer::class);
    }

    /**
     * Scope for pending jobs
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope for failed jobs
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope for jobs that need retry
     */
    public function scopeNeedsRetry($query)
    {
        return $query->where('status', 'retrying')
            ->where('retry_count', '<', DB::raw('max_retries'))
            ->where(function($q) {
                $q->whereNull('next_retry_at')
                  ->orWhere('next_retry_at', '<=', now());
            });
    }

    /**
     * Mark job as completed
     */
    public function markCompleted()
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    /**
     * Mark job as failed
     */
    public function markFailed($error = null)
    {
        $this->update([
            'status' => 'failed',
            'last_error' => $error,
            'completed_at' => now(),
        ]);
    }

    /**
     * Increment retry count
     */
    public function incrementRetry($error = null)
    {
        $this->update([
            'retry_count' => $this->retry_count + 1,
            'status' => 'retrying',
            'last_error' => $error,
            'next_retry_at' => now()->addMinutes(pow(2, $this->retry_count)), // Exponential backoff
        ]);
    }
}
