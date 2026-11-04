<script type="application/json" data-rawprint-config>@json($config)</script>
@foreach ($sources as $source)
<script src="{{ $source }}"></script>
@endforeach
