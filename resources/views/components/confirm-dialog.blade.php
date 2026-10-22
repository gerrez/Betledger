{{--
    The one confirmation dialog, opened by any button with a `data-confirm` message
    (see resources/js/app.js). Rendered once, in the app layout.
--}}
<div x-data="confirmDialog" x-on:confirm-action.window="open($event.detail)">
    <flux:modal name="confirm-action" x-on:close="proceed = null" class="w-full md:w-[28rem]">
        <div class="space-y-6">
            <div class="space-y-2">
                <flux:heading size="lg" x-text="title"></flux:heading>
                <flux:text x-text="message"></flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" data-confirm-cancel x-on:click="$flux.modal('confirm-action').close()">Cancel</flux:button>

                <flux:button variant="danger" x-show="danger" x-on:click="confirm()"><span x-text="button"></span></flux:button>
                <flux:button variant="primary" x-show="! danger" x-on:click="confirm()"><span x-text="button"></span></flux:button>
            </div>
        </div>
    </flux:modal>
</div>
