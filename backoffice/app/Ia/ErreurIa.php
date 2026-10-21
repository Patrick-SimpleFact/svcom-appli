<?php

namespace App\Ia;

use RuntimeException;

/** L'assistant IA n'a pas pu obtenir de réponse exploitable : l'élément reste à décider par un humain. */
class ErreurIa extends RuntimeException {}
