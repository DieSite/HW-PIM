<x-admin::layouts>
    <div class="flex flex-col gap-4 max-w-7xl mx-auto p-6">
        <!-- Header -->
        <div class="flex justify-between items-center border-b pb-4">
            <h1 class="text-2xl text-gray-800 dark:text-white font-bold">
                Edit Bol.com Credentials
            </h1>
        </div>

        <!-- Content -->
        <div class="bg-white dark:bg-cherry-800 rounded-lg shadow-sm p-6">
            <form method="POST" action="{{ route('admin.custom.bolCom.update', $credential->id) }}" class="space-y-6">
                @csrf
                @method('PUT')

                <div class="max-w-xl">
                    <div class="mb-4">
                        <label for="name" class="block text-sm font-medium mb-1 required">Name</label>
                        <input
                            type="text"
                            id="name"
                            name="name"
                            class="w-full p-2 border border-gray-300 rounded-md dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                            value="{{ old('name', $credential->name) }}"
                            required
                        >
                        @if ($errors->has('name'))
                            <span class="text-red-500 text-sm">{{ $errors->first('name') }}</span>
                        @endif
                    </div>

                    <div class="mb-4">
                        <label for="client_id" class="block text-sm font-medium mb-1 required">Client ID</label>
                        <input
                            type="text"
                            id="client_id"
                            name="client_id"
                            class="w-full p-2 border border-gray-300 rounded-md dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                            value="{{ old('client_id', $credential->client_id) }}"
                            required
                        >
                        @if ($errors->has('client_id'))
                            <span class="text-red-500 text-sm">{{ $errors->first('client_id') }}</span>
                        @endif
                    </div>

                    <div class="mb-4">
                        <label for="client_secret" class="block text-sm font-medium mb-1">Client Secret</label>
                        <input
                            type="password"
                            id="client_secret"
                            name="client_secret"
                            class="w-full p-2 border border-gray-300 rounded-md dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                            placeholder="Leave blank to keep current secret"
                        >
                        @if ($errors->has('client_secret'))
                            <span class="text-red-500 text-sm">{{ $errors->first('client_secret') }}</span>
                        @endif
                    </div>

                    <div class="mb-4">
                        <label for="is_active" class="block text-sm font-medium mb-1">Status</label>
                        <select
                            id="is_active"
                            name="is_active"
                            class="w-full p-2 border border-gray-300 rounded-md dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                        >
                            <option value="1" {{ old('is_active', $credential->is_active) == 1 ? 'selected' : '' }}>Active</option>
                            <option value="0" {{ old('is_active', $credential->is_active) == 0 ? 'selected' : '' }}>Inactive</option>
                        </select>
                    </div>
                </div>

                <div class="flex items-center gap-4 mt-6">
                    <button type="submit" class="primary-button">
                        Update Credentials
                    </button>
                    <a href="{{ route('admin.custom.bolCom.index') }}" class="secondary-button">
                        Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
</x-admin::layouts>
