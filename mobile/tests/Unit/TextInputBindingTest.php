<?php

it('binds text inputs live so a focused field is never lost when a button is tapped', function () {
    $views = glob(dirname(__DIR__, 2).'/resources/views/native/**/*.blade.php');

    $offenders = array_filter($views, fn (string $view): bool => (bool) preg_match('/native:model\.(blur|lazy)/', file_get_contents($view)));

    expect($offenders)->toBeEmpty();
});
