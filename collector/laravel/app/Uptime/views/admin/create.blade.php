@extends('layouts.admin')

@section('title')
    Add monitored node
@endsection

@section('content-header')
    <h1>Add monitored node</h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li><a href="{{ route('admin.uptime') }}">Uptime Monitoring</a></li>
        <li class="active">Add</li>
    </ol>
@endsection

@section('content')
    <div class="row"><div class="col-md-8">
        <form method="POST" action="{{ route('admin.uptime.nodes.store') }}" class="box box-primary">
            @csrf
            <div class="box-body">@include('uptime::admin.form-fields', ['node' => null])</div>
            <div class="box-footer"><button class="btn btn-primary">Create</button></div>
        </form>
    </div></div>
@endsection
