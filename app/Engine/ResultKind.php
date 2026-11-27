<?php

namespace App\Engine;

enum ResultKind: string
{
    case Complete = 'complete';
    case Fail = 'fail';
    case Wait = 'wait';
    case Terminate = 'terminate';
    case Iterate = 'iterate';
    case RunChild = 'run_child';
    case UpdateVariable = 'update_variable';
}
