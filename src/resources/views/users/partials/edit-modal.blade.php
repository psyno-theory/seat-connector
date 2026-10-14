<div class="modal" tabindex="-1" id="userModal">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">{{ trans('seat-connector::seat.edit_user_mapping') }}</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <form action="{{ route("seat-connector.users.edit") }}" method="POST">
                    @csrf()
                    <input type="hidden" name="user_id">
                    <div class="form-group">
                        <label for="name-override">{{ trans('seat-connector::seat.name_override') }}</label>
                        <input type="text" class="form-control mb-1" id="name-override" name="name_override" placeholder="{{ trans('seat-connector::seat.enter_custom_name') }}">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="name_override_enable" id="name-override-enable">
                            <label class="form-check-label" for="name-override-enable">
                                {{ trans('seat-connector::seat.enable_name_override') }}
                            </label>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">{{ trans('seat-connector::seat.save') }}</button>
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ trans('seat-connector::seat.close') }}</button>
                </form>

                @can('global.superuser')
                    <form id="strict-exemptions-form" class="mt-4" style="display: none">
                        @csrf()
                        <h6>Discord Strict character exemptions</h6>
                        <p class="small text-muted">
                            Checked characters may have invalid tokens without blocking this account.
                            At least one valid, non-exempt character is required.
                            Exemptions may restore all otherwise eligible managed Discord roles.
                        </p>
                        <div id="strict-exemptions-status" class="small mb-2" role="status" aria-live="polite"></div>
                        <div id="strict-exemptions-characters" class="mb-3"></div>
                        <button type="submit" class="btn btn-primary" disabled>Save exemptions</button>
                    </form>
                @endcan
            </div>
        </div>
    </div>
</div>

@push('javascript')
    <script>
        let strictLoadRequest = null
        let strictVersion = 0
        let strictUpdateUrl = null

        $('#userModal').on('show.bs.modal', function (event) {
            const button = $(event.relatedTarget)
            const modal = $(this)
            const user_id = button.data('user-id')
            modal.find('.modal-body input[name="user_id"]').val(user_id)

            const name_override = button.data('name-override')
            modal.find('.modal-body input[name="name_override"]').val(name_override)
            modal.find('.modal-body input[name="name_override_enable"]').prop('checked', name_override !== "")

            strictVersion += 1
            const version = strictVersion
            if (strictLoadRequest) strictLoadRequest.abort()
            strictUpdateUrl = null

            const form = $('#strict-exemptions-form')
            const characters = $('#strict-exemptions-characters')
            const status = $('#strict-exemptions-status')
            form.find('button[type="submit"]').prop('disabled', true)
            characters.empty()

            const isDiscord = button.data('connector-type') === 'discord'
            form.toggle(isDiscord)
            if (!form.length || !isDiscord) return

            status.text('Loading characters…')
            strictLoadRequest = $.getJSON(button.data('strict-exemptions-url'))
                .done(function (data) {
                    if (version !== strictVersion) return
                    if (!data || !Array.isArray(data.characters) || typeof data.update_url !== 'string') {
                        status.text('Could not load exemptions. Close and reopen this dialog to retry.')
                        return
                    }

                    data.characters.forEach(function (character) {
                        const id = 'strict-exempt-character-' + character.id;
                        const row = $('<div>').addClass('form-check mb-1');
                        const input = $('<input>').addClass('form-check-input')
                            .attr({ type: 'checkbox', id: id, name: 'characters[]', value: character.id })
                            .prop('checked', character.exempt);
                        
                        // Define the styled HTML using your specific icons, Bootstrap text colors, and Blade translations
                        const validHtml = '<span class="text-success"><i class="fas fa-check-circle"></i>Valid Token</span>';
                        const invalidHtml = '<span class="text-danger"><i class="fas fa-times-circle"></i>Invalid Token</span>';
                        
                        // Determine which status to show
                        const statusIndicator = character.has_token ? validHtml : invalidHtml;

                        // Use .html() instead of .text() to render the tags
                        const label = $('<label>').addClass('form-check-label').attr('for', id)
                            .html(character.name + ' - ' + statusIndicator);
                            
                        row.append(input, label);
                        characters.append(row);
                    });

                    strictUpdateUrl = data.update_url
                    status.text(data.characters.length ? '' : 'No linked characters.')
                    form.find('button[type="submit"]').prop('disabled', false)
                })
                .fail(function (xhr, state) {
                    if (version !== strictVersion || state === 'abort') return
                    status.text('Could not load exemptions. Close and reopen this dialog to retry.')
                })
                .always(function () {
                    if (version === strictVersion) strictLoadRequest = null
                })
        })

        $('#userModal').on('hidden.bs.modal', function () {
            strictVersion += 1
            strictUpdateUrl = null
            if (strictLoadRequest) strictLoadRequest.abort()
            $('#strict-exemptions-form button[type="submit"]').prop('disabled', true)
        })

        $('#strict-exemptions-form').on('submit', function (event) {
            event.preventDefault()
            if (!strictUpdateUrl) return

            const form = $(this)
            const submit = form.find('button[type="submit"]')
            if (submit.prop('disabled')) return

            const version = strictVersion
            const characters = form.find('input[name="characters[]"]:checked').map(function () {
                return Number(this.value)
            }).get()

            submit.prop('disabled', true)
            form.find('input[name="characters[]"]').prop('disabled', true)
            $('#strict-exemptions-status').text('Saving exemptions…')

            $.ajax({
                url: strictUpdateUrl,
                method: 'PUT',
                contentType: 'application/json',
                dataType: 'json',
                data: JSON.stringify({
                    _token: form.find('input[name="_token"]').val(),
                    characters: characters
                })
            }).done(function (data) {
                if (version !== strictVersion) return
                const message = data && data.success === true && typeof data.message === 'string'
                    ? data.message
                    : 'Could not confirm the save. Close and reopen this dialog to check the saved exemptions.'
                $('#strict-exemptions-status').text(message)
            }).fail(function (xhr) {
                if (version !== strictVersion) return
                const data = xhr.responseJSON
                const message = data && data.errors && data.errors.characters
                    ? data.errors.characters[0]
                    : 'Could not confirm the save. Close and reopen this dialog to check the saved exemptions.'
                $('#strict-exemptions-status').text(message)
            }).always(function () {
                if (version === strictVersion) {
                    submit.prop('disabled', false)
                    form.find('input[name="characters[]"]').prop('disabled', false)
                }
            })
        })

        $('#name-override').on('input', function () {
            $('#userModal').find('.modal-body input[name="name_override_enable"]')
                .prop('checked', $(this).val().length > 0)
        })
    </script>
@endpush
