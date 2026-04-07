@extends('landing.layout')

@section('content')
    @include('landing.sections.hero')

    @if($tenant->description)
        @include('landing.sections.about')
    @endif

    @if(count($services) > 0)
        @include('landing.sections.services')
    @endif

    @if($team->count() > 0)
        @include('landing.sections.team')
    @endif

    @if($plans->count() > 0)
        @include('landing.sections.plans')
    @endif

    @include('landing.sections.contact')
    @include('landing.sections.footer')
@endsection
