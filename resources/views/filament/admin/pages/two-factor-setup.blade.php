<x-filament-panels::page>
    @if ($recoveryCodes)
        <div class="space-y-4">
            <x-filament::section>
                <x-slot name="heading">Recovery codes</x-slot>
                <x-slot name="description">
                    Store these somewhere safe. Each can be used once if you lose your device.
                    They will not be shown again.
                </x-slot>

                <div class="grid grid-cols-2 gap-2 font-mono text-sm">
                    @foreach ($recoveryCodes as $rc)
                        <div class="rounded bg-gray-100 px-3 py-2 dark:bg-gray-800">{{ $rc }}</div>
                    @endforeach
                </div>
            </x-filament::section>

            <div class="flex justify-end">
                {{ $this->continueAction }}
            </div>
        </div>
    @else
        <div class="space-y-6">
            <x-filament::section>
                <x-slot name="heading">Scan with your authenticator</x-slot>
                <x-slot name="description">
                    Use Google Authenticator, 1Password, Authy, or any TOTP-compatible app.
                </x-slot>

                <div class="flex flex-col items-center gap-4 md:flex-row md:items-start">
                    <div class="shrink-0 rounded-lg bg-white p-3">
                        {!! $this->qrSvg() !!}
                    </div>

                    <div class="flex-1 space-y-3">
                        <div>
                            <div class="text-sm text-gray-500 dark:text-gray-400">Manual entry key</div>
                            <div class="font-mono text-sm break-all rounded bg-gray-100 px-3 py-2 dark:bg-gray-800">
                                {{ $secret }}
                            </div>
                        </div>
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            After scanning, enter the 6-digit code your app generates below.
                        </p>
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">Verify</x-slot>

                <form wire:submit="verifyAction">
                    {{ $this->form }}

                    <div class="mt-4 flex justify-end">
                        {{ $this->verifyAction }}
                    </div>
                </form>
            </x-filament::section>
        </div>
    @endif
</x-filament-panels::page>
