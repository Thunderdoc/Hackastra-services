<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta id="csrf-token" name="csrf-token" content="{{ csrf_token() }}">
    <meta name="author" content="{{ Qs::getSystemName() }}">

    <title> @yield('page_title') | {{ Qs::getAppCode() }} </title>

    @include('partials.inc_top')
</head>

<body class="{{ in_array(Route::currentRouteName(), ['ttr.manage', 'ttr.show']) ? 'sidebar-xs' : '' }}">

@include('partials.top_menu')
<div class="page-content">
    @include('partials.menu')
    <div class="content-wrapper">
        @include('partials.header')

        <div class="content">
            {{--Error Alert Area (Loop 9 · UX Fix 4: the summary is an index —
                each message links to its field's id; the error also renders
                at the field itself via partials/errors/field).--}}
            @if($errors->any())
                <div class="alert alert-danger border-0 alert-dismissible" id="form-error-summary">
                    <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>

                        @foreach($errors->getMessages() as $field => $messages)
                            @foreach($messages as $er)
                                <span><i class="icon-arrow-right5"></i> <a href="#{{ $field }}" class="alert-link">{{ $er }}</a></span> <br>
                            @endforeach
                        @endforeach

                </div>
            @endif
            <div id="ajax-alert" style="display: none"></div>

            @yield('content')
        </div>


    </div>
</div>

@include('partials.inc_bottom')
@yield('scripts')
</body>
</html>
