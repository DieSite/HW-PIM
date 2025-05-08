<x-admin::layouts>
    <div class="flex flex-col gap-4 max-w-7xl mx-auto p-6">
        <!-- Header -->
        <div class="flex justify-between items-center border-b pb-4">
            <h1 class="text-2xl text-gray-800 dark:text-white font-bold">
                Bol.com Credentials
            </h1>

            <div class="flex gap-3">
                <!-- Bulk Sync Form -->
                <form method="POST" action="{{ route('admin.custom.bolCom.bulkSync') }}" class="flex items-center gap-2">
                    @csrf
                    <select
                        name="credential_id"
                        class="border border-gray-300 dark:border-gray-700 rounded px-3 py-1 text-sm focus:outline-none focus:border-blue-500 dark:bg-cherry-900 dark:text-white"
                    >
                        <option value="">Select Credentials</option>
                        @foreach($credentials->where('is_active', true) as $credential)
                            <option value="{{ $credential->id }}">{{ $credential->name }}</option>
                        @endforeach
                    </select>
                    <button
                        type="submit"
                        class="secondary-button flex items-center gap-1.5"
                        title="Sync all products with an EAN to Bol.com"
                    >
                        <i class="icon-refresh text-lg"></i>
                        Bulk Sync Products
                    </button>
                </form>

                <a href="{{ route('admin.custom.bolCom.create') }}" class="primary-button">
                    <span class="flex items-center gap-1.5">
                        <i class="icon-plus text-xl"></i>
                        Create Credentials
                    </span>
                </a>
            </div>
        </div>

        <!-- Content -->
        <div class="bg-white dark:bg-cherry-800 rounded-lg shadow-sm p-6">
            @if (session('success'))
                <div class="mb-4 p-4 rounded-lg bg-green-50 dark:bg-green-900/10 text-green-700 dark:text-green-600">
                    {{ session('success') }}
                </div>
            @endif

            @if (session('error'))
                <div class="mb-4 p-4 rounded-lg bg-red-50 dark:bg-red-900/10 text-red-700 dark:text-red-600">
                    {{ session('error') }}
                </div>
            @endif

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                    <tr class="border-b">
                        <th class="px-4 py-3 font-medium">ID</th>
                        <th class="px-4 py-3 font-medium">Name</th>
                        <th class="px-4 py-3 font-medium">Client ID</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 font-medium">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    @if(count($credentials) > 0)
                        @foreach($credentials as $credential)
                            <tr class="border-b dark:border-gray-700">
                                <td class="px-4 py-3">{{ $credential->id }}</td>
                                <td class="px-4 py-3">{{ $credential->name }}</td>
                                <td class="px-4 py-3">{{ $credential->client_id }}</td>
                                <td class="px-4 py-3">
                                        <span class="px-2 py-1 rounded text-xs font-medium {{ $credential->is_active ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300' : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300' }}">
                                            {{ $credential->is_active ? 'Active' : 'Inactive' }}
                                        </span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex gap-2">
                                        <a
                                            href="{{ route('admin.custom.bolCom.test', $credential->id) }}"
                                            class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
                                        >
                                            Test
                                        </a>
                                        <a
                                            href="{{ route('admin.custom.bolCom.edit', $credential->id) }}"
                                            class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
                                        >
                                            Edit
                                        </a>
                                        <form method="POST" action="{{ route('admin.custom.bolCom.destroy', $credential->id) }}" class="inline">
                                            @csrf
                                            @method('DELETE')
                                            <button
                                                type="submit"
                                                class="text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300 focus:outline-none bg-transparent"
                                                onclick="return confirm('Are you sure you want to delete these credentials?')"
                                            >
                                                Delete
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    @else
                        <tr class="border-b dark:border-gray-700">
                            <td colspan="5" class="px-4 py-3 text-center">
                                No credentials found
                            </td>
                        </tr>
                    @endif
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-admin::layouts>
