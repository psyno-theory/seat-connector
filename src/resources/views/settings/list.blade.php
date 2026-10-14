@extends('web::layouts.grids.12')

@section('title', trans_choice('seat-connector::seat.settings', 0))
@section('page_header', trans_choice('seat-connector::seat.settings', 0))

@section('full')

  <div class="row">

    <div class="col-md-3">

      <div class="card">
        <div class="card-header">
          <h3 class="card-title">Common</h3>
        </div>
        <div class="card-body">
          <form method="post" id="seat-connector-setup">
            {{ csrf_field() }}
            <div class="form-group">
              <label for="security-level">Security Level</label>
              <select id="security-level" name="security-level" class="form-control">
                @if(setting('seat-connector.strict', true))
                  <option value="strict" selected>Strict</option>
                @else
                  <option value="strict">Strict</option>
                @endif
                @if(setting('seat-connector.strict', true))
                    <option value="normal">Normal</option>
                @else
                  <option value="normal" selected>Normal</option>
                @endif
              </select>
            </div>
            <div class="form-group">
              <label for="use-ticker">Use ticker ?</label>
              <div class="radio">
                <label>
                  @if(setting('seat-connector.ticker', true))
                    <input type="radio" id="use-ticker" name="use-ticker" value="0" /> No
                  @else
                    <input type="radio" id="use-ticker" name="use-ticker" value="0" checked /> No
                  @endif
                </label>
                <label>
                  @if(setting('seat-connector.ticker', true))
                  <input type="radio" id="use-ticker" name="use-ticker" value="1" checked /> Yes
                  @else
                  <input type="radio" id="use-ticker" name="use-ticker" value="1" /> Yes
                  @endif
                </label>
              </div>
            </div>
            <div class="form-group">
              <label for="prefix-format">Prefix mask</label>
              @if(setting('seat-connector.format', true) == '')
                <input type="text" id="prefix-format" name="prefix-format" class="form-control" value="[%2$s] %1$s" />
              @else
                <input type="text" id="prefix-format" name="prefix-format" class="form-control" value="{{ setting('seat-connector.format', true) }}" />
              @endif
            </div>
          </form>
        </div>
        <div class="card-footer clearfix">
          <button type="submit" class="btn btn-success float-right" form="seat-connector-setup">{{ trans('seat-connector::seat.save') }}</button>
        </div>
      </div>

    </div>

    <div class="col-md-9">

      <div class="card">

        <div class="card-header">
          <h3 class="card-title">Management</h3>
        </div>

        <div class="card-body">

          <div class="row">
            <div class="col-6 no-padding">

              <div class="row">

                <div class="col-6">
                  <button type="button" role="button" id="seat-connector-sets" class="btn btn-success btn-block btn-flat">Update Sets</button>
                  <span class="help-block">This will update known Sets from selected Drivers.</span>
                </div>

                <div class="col-6">
                  <button type="button" role="button" id="seat-connector-terminator" class="btn btn-danger btn-block btn-flat">Reset Everybody</button>
                  <span class="help-block">This will remove all Set from every Users on selected Drivers.</span>
                </div>

              </div>

              <div class="row">

                <div class="col-6">
                  <button type="button" role="button" id="seat-connector-policy" class="btn btn-success btn-block btn-flat">Sync. Users</button>
                  <span class="help-block">This will apply Users policy to selected Drivers.</span>
                </div>

                <div class="col-6">
                  <select id="driver-selector" class="form-control">
                    <option value="">All Drivers</option>
                    @foreach($drivers as $key => $driver)
                    <option value="{{ $key }}">{{ ucfirst($driver->name) }}</option>
                    @endforeach
                  </select>
                  <span class="help-block">This will determine for which driver action have to be queue.</span>
                </div>

              </div>

            </div>

            <div class="col-6">

              <dl class="dl-horizontal">
                <dt>Security Level</dt>
                <dd>Determine how policy must be applied. Using <code>strict</code> will require user have all their linked character with a valid ESI access. Otherwise, they will loose all their access.</dd>

                <dt>Use Ticker</dt>
                <dd>If yes is selected, the Character Corporation Ticker will be added to the name according to <code>prefix mask</code>.</dd>

                <dt>Prefix Mask</dt>
                <dd>This is the pattern which will define how ticker must be put. <code>%1$s</code> is referring to <b>Character Name</b>. <code>%2$s</code> is referring to <b>Corporation Ticker</b>. <code>%3$s</code> is referring to <b>Alliance Ticker</b>.</dd>
              </dl>

            </div>

          </div>

        </div>

      </div>

    </div>

  </div>

  @foreach($drivers->split(ceil($drivers->count() / 3)) as $row)

    <div class="row">

      @foreach($row as $key => $driver)

        <div class="col-md-3">

          <div class="card">
            <div class="card-header">
              <h4 class="card-title">
                <i class="{{ $driver->icon }}"></i>
                {{ ucfirst($driver->name) }}
              </h4>
            </div>
            <div class="card-body">
              <form role="form" method="post" id="{{ sprintf('seat-connector-%s', $key) }}" action="{{ route(sprintf('seat-connector.drivers.%s.settings', $key)) }}">
                {{ csrf_field() }}

                @foreach($driver->settings as $field)
                  @switch($field->type)
                    @case("checkbox")
                      <div class="form-group">
                        <label for="{{ sprintf('seat-connector-%s-%s', $key, $field->name) }}">{{ trans($field->label) }}</label>
                        <input type="checkbox" name="{{ $field->name }}"
                              id="{{ sprintf('seat-connector-%s-%s', $key, $field->name) }}" class="form-check"
                              value="1" @if($field->value == 1) checked @endif />
                      </div>
                      @break
                    @default
                    <div class="form-group">
                      <label for="{{ sprintf('seat-connector-%s-%s', $key, $field->name) }}">{{ trans($field->label) }}</label>
                      <input type="text" name="{{ $field->name }}"
                            id="{{ sprintf('seat-connector-%s-%s', $key, $field->name) }}" class="form-control"
                            value="{{ $field->value }}" />
                    </div>
                  @endswitch
                @endforeach
              </form>
            </div>
            <div class="card-footer">
              <button type="submit" form="{{ sprintf('seat-connector-%s', $key) }}" class="btn btn-success float-right">{{ trans('seat-connector::seat.save') }}</button>
            </div>
          </div>

        </div>

      @endforeach

    </div>

  @endforeach

  <div class="row">
    <div class="col-12">
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">{{ trans('seat-connector::seat.excluded_sets') }}</h3>
        </div>
        <div class="card-body">
          <p class="text-muted">{{ trans('seat-connector::seat.excluded_sets_help') }}</p>

          <div class="row mb-3">
            <div class="col-md-4">
              <select id="excluded-sets-driver" class="form-control">
                <option value="">{{ trans('seat-connector::seat.select_driver') }}</option>
                @foreach($drivers as $key => $driver)
                  <option value="{{ $key }}">{{ ucfirst($driver->name) }}</option>
                @endforeach
              </select>
            </div>
          </div>

          <table class="table table-striped" id="excluded-sets-table">
            <thead>
              <tr>
                <th>{{ trans('seat-connector::seat.set_name') }}</th>
                <th>{{ trans('seat-connector::seat.driver') }}</th>
                <th class="text-center">{{ trans('seat-connector::seat.excluded') }}</th>
              </tr>
            </thead>
            <tbody id="excluded-sets-body">
              <tr>
                <td colspan="3" class="text-center text-muted">{{ trans('seat-connector::seat.select_driver_first') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

@stop

@push('javascript')
  <script>
    $('#seat-connector-sets, #seat-connector-policy, #seat-connector-terminator').on('click', function (e) {
        var command = '';
        var driver  = $('#driver-selector').val();

        switch (e.target.id) {
            case 'seat-connector-sets':
                command = 'seat-connector:sync:sets';
                break;
            case 'seat-connector-policy':
                command = 'seat-connector:apply:policies';
                break;
            case 'seat-connector-terminator':
                command = 'seat-connector:apply:policies --terminator';
                break;
        }

        $.ajax({
            'method': 'post',
            'url': '{{ route('seat-connector.settings.command') }}',
            'data': {
                'driver': driver,
                'command': command
            },
        });
    });

    // Excluded sets functionality
    $('#excluded-sets-driver').on('change', function() {
        var driver = $(this).val();
        var tbody = $('#excluded-sets-body');

        if (!driver) {
            tbody.html('<tr><td colspan="3" class="text-center text-muted">{{ trans('seat-connector::seat.select_driver_first') }}</td></tr>');
            return;
        }

        tbody.html('<tr><td colspan="3" class="text-center"><i class="fas fa-spinner fa-spin"></i> {{ trans('seat-connector::seat.loading') }}</td></tr>');

        $.ajax({
            'method': 'get',
            'url': '{{ route('seat-connector.api.sets.all') }}',
            'data': { 'driver': driver },
            'success': function(response) {
                if (response.results.length === 0) {
                    tbody.html('<tr><td colspan="3" class="text-center text-muted">{{ trans('seat-connector::seat.no_sets_found') }}</td></tr>');
                    return;
                }

                var html = '';
                response.results.forEach(function(set) {
                    var checked = set.is_excluded ? 'checked' : '';
                    html += '<tr>' +
                        '<td>' + set.name + '</td>' +
                        '<td><span class="badge badge-secondary">' + set.connector_type + '</span></td>' +
                        '<td class="text-center">' +
                        '<input type="checkbox" class="excluded-set-toggle" data-set-id="' + set.id + '" ' + checked + '>' +
                        '</td>' +
                        '</tr>';
                });
                tbody.html(html);
            },
            'error': function() {
                tbody.html('<tr><td colspan="3" class="text-center text-danger">{{ trans('seat-connector::seat.error_loading_sets') }}</td></tr>');
            }
        });
    });

    $(document).on('change', '.excluded-set-toggle', function() {
        var checkbox = $(this);
        var setId = checkbox.data('set-id');
        var excluded = checkbox.is(':checked') ? 1 : 0;

        $.ajax({
            'method': 'post',
            'url': '{{ route('seat-connector.sets.exclude') }}',
            'data': {
                'set_id': setId,
                'excluded': excluded
            },
            'success': function(response) {
                if (response.success) {
                    // Show a brief visual feedback
                    checkbox.closest('tr').addClass('table-success');
                    setTimeout(function() {
                        checkbox.closest('tr').removeClass('table-success');
                    }, 500);
                }
            },
            'error': function() {
                // Revert the checkbox state on error
                checkbox.prop('checked', !checkbox.is(':checked'));
                alert('{{ trans('seat-connector::seat.error_updating_set') }}');
            }
        });
    });
  </script>
@endpush
