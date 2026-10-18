<?php

namespace App\Modules\Analytics\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A tenant's warehouse, so their own BI tools can read their own data.
 *
 * Power BI does not connect to this platform; it connects to a warehouse. So the useful thing to
 * build is not a Power BI integration but a push into the warehouse the customer already points
 * Power BI at — which for the customers asking is Databricks.
 *
 * ── The token ────────────────────────────────────────────────────────────────
 *
 * `credentials` is cast encrypted. That is the whole security story of this model: the value can
 * read and write a customer's warehouse, so it is encrypted at rest, never serialised to a page,
 * and never logged. `forDisplay()` is what the settings screen gets, and it deliberately cannot
 * return it.
 */
class BiConnection extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $table = 'bi_connections';

    protected $fillable = [
        'uuid', 'company_id', 'provider', 'name', 'host', 'http_path',
        'catalog', 'schema_name', 'credentials', 'is_active', 'created_by',
    ];

    protected $casts = [
        // The reason this model exists in one line: a warehouse token is not stored in the clear.
        'credentials'      => 'encrypted:array',
        'is_active'        => 'boolean',
        'last_tested_at'   => 'datetime',
        'last_exported_at' => 'datetime',
    ];

    /**
     * Never sent to a browser, so it cannot be read out of a page's props.
     *
     * `$hidden` is belt to `forDisplay()`'s braces: any future code that returns this model
     * straight to Inertia gets the same protection without having to remember it.
     */
    protected $hidden = ['credentials'];

    public const PROVIDERS = ['databricks'];

    protected static function booted(): void
    {
        static::creating(function (self $connection) {
            $connection->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function token(): ?string
    {
        return $this->credentials['token'] ?? null;
    }

    public function hasToken(): bool
    {
        return filled($this->token());
    }

    /**
     * Everything a person configuring this needs to see, and nothing they should not.
     *
     * Whether a token is set, rather than the token. Somebody checking their configuration needs to
     * know the credential is there; nobody needs it back out of the form they typed it into, and a
     * page that returns it puts it in the browser's memory, the Inertia payload and any error
     * report that captures either.
     *
     * @return array<string, mixed>
     */
    public function forDisplay(): array
    {
        return [
            'id'          => $this->uuid,
            'provider'    => $this->provider,
            'name'        => $this->name,
            'host'        => $this->host,
            'http_path'   => $this->http_path,
            'catalog'     => $this->catalog,
            'schema'      => $this->schema_name,
            'is_active'   => (bool) $this->is_active,
            'has_token'   => $this->hasToken(),
            'last_tested_at'   => $this->last_tested_at?->toDayDateTimeString(),
            'last_test_error'  => $this->last_test_error,
            'last_exported_at' => $this->last_exported_at?->toDayDateTimeString(),
        ];
    }
}
