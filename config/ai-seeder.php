<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Chunk Size
    |--------------------------------------------------------------------------
    |
    | The number of rows generated per AI request. Smaller chunks reduce
    | token usage and avoid timeouts but increase the number of API calls.
    |
    */

    'chunk_size' => env('AI_SEEDER_CHUNK_SIZE', 50),

    /*
    |--------------------------------------------------------------------------
    | Default Row Count
    |--------------------------------------------------------------------------
    |
    | The default number of rows to generate when the --count option is
    | not provided to the ai:seed command.
    |
    */

    'default_count' => env('AI_SEEDER_DEFAULT_COUNT', 10),

    /*
    |--------------------------------------------------------------------------
    | Max Retries
    |--------------------------------------------------------------------------
    |
    | The number of times the AI generation will be retried if it returns
    | invalid data (malformed JSON, wrong row count, etc.).
    |
    */

    'max_retries' => env('AI_SEEDER_MAX_RETRIES', 3),

];
