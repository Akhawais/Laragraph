<?php

namespace Scriptle\Laragraph;

use App\GraphQL\GraphQL;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class LaragraphClearCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gql:clear';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear cached Laragraph attribute tags';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        foreach (config('laragraph.schemas') as $name => $schema) {
            $filename = 'laragraph-' . Str::slug($name) . '-cache.php';
            if (file_exists(storage_path($filename))) unlink(storage_path($filename));
        }
        return 0;
    }
}
