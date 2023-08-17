<?php

use LanguageDetection\Trainer;
use Loupe\Loupe\Internal\Tokenizer\Tokenizer;
use Symfony\Component\Filesystem\Filesystem;

require_once 'vendor/autoload.php';

$fs = new Filesystem();
$fs->mirror(
    __DIR__ . '/../vendor/patrickschur/language-detection/resources',
    Tokenizer::getNgramsDir(),
    null,
    ['override' => true]
);

$t = new Trainer();
$t->setMaxNgrams(Tokenizer::MAX_NGRAMS);
$t->learn(Tokenizer::getNgramsDir());