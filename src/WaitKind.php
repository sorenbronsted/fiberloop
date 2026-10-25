<?php

namespace bronsted;

enum WaitKind
{
    case Ready;
    case Timer;
    case Read;
    case Write;
    case Park;
}
