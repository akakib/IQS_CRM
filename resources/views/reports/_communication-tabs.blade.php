{{-- Communication report: one page, three tabs, same dates on each. @include with ['active' => 'chats'|'calls'|'riders'] --}}
<x-tabs :tabs="[
    'chats' => [__('Chats'), route('chat-report.index', ['from' => $from->toDateString(), 'to' => $to->toDateString()])],
    'calls' => [__('Customer calls'), route('calls-report.index', ['from' => $from->toDateString(), 'to' => $to->toDateString()])],
    'riders' => [__('Rider calls'), route('riders-report.index', ['from' => $from->toDateString(), 'to' => $to->toDateString()])],
]" :active="$active" />
