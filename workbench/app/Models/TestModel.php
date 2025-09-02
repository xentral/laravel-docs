<?php declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Workbench\Database\Factories\TestModelFactory;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class TestModel extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function newFactory(): TestModelFactory
    {
        return TestModelFactory::new();
    }
}
