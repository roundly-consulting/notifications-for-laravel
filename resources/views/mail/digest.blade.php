<x-mail::message>
# {{ $greeting }}

{{ $intro }}

@foreach ($groups as $context => $categories)
@if (count($groups) > 1 || $context !== '')
## {{ $context !== '' ? $context : $noContext }}
@endif

@foreach ($categories as $category => $lines)
**{{ $category }}**

@foreach ($lines as $entry)
@if ($entry['url'] !== null)
- [{{ $entry['line'] }}]({{ $entry['url'] }})
@else
- {{ $entry['line'] }}
@endif
@endforeach

@endforeach
@endforeach
@if ($more !== null)
{{ $more }}
@endif

@if ($footer !== null)
{{ $footer }}
@endif
</x-mail::message>
