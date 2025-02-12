<x-admin::layouts>
    <div class="flex flex-col gap-4 max-w-7xl mx-auto p-6">
        <!-- Header -->
        <div class="flex justify-between items-center border-b pb-4">
            <h1 class="text-2xl text-gray-800 dark:text-white font-bold">
                Product Import/Export
            </h1>

            <!-- Queue Statistics -->
            <div class="flex gap-4">
                <div class="bg-white dark:bg-cherry-800 rounded-lg shadow p-4">
                    <span class="block text-sm text-gray-500 dark:text-gray-400">Pending Jobs</span>
                    <span class="text-2xl font-bold text-violet-600 dark:text-violet-400">
                        {{ $queue['pending'] }}
                    </span>
                </div>
            </div>
        </div>

        <!-- Import/Export Grid -->
        <div class="grid grid-cols-2 gap-6">
            <!-- Import Section -->
            <div class="bg-white dark:bg-cherry-800 rounded-lg shadow-sm p-6">
                <h2 class="text-lg font-medium text-gray-700 dark:text-gray-300 mb-4">
                    Product Import
                </h2>

                <form method="POST" action="{{ route('admin.custom.imports.upload') }}" enctype="multipart/form-data" class="space-y-6">
                    @csrf
                    <div class="max-w-xl">
                        <x-admin::form.control-group>
                            <x-admin::form.control-group.label class="text-sm font-medium">
                                Select File
                            </x-admin::form.control-group.label>

                            <x-admin::form.control-group.control
                                type="file"
                                name="file"
                                rules="required|mimes:xlsx,xls,csv"
                                class="border-2 border-dashed border-gray-300 dark:border-gray-700 rounded-lg p-4 w-full"
                            />
                        </x-admin::form.control-group>
                    </div>

                    <div class="flex items-center gap-4">
                        <button
                            type="submit"
                            class="primary-button"
                        >
                            <span class="flex items-center gap-1.5">
                                <i class="icon-upload text-xl"></i>
                                Import Products
                            </span>
                        </button>

                        @if($queue['pending'] > 0)
                            <span class="text-sm text-gray-500 dark:text-gray-400">
                                There are pending imports in the queue
                            </span>
                        @endif
                    </div>
                </form>
            </div>

            <!-- Export Section -->
            <div class="bg-white dark:bg-cherry-800 rounded-lg shadow-sm p-6">
                <h2 class="text-lg font-medium text-gray-700 dark:text-gray-300 mb-4">
                    Product Export
                </h2>

                <form method="POST" action="" class="space-y-6">
                    @csrf
                    <div class="space-y-4">
                        <x-admin::form.control-group>
                            <x-admin::form.control-group.label class="text-sm font-medium">
                                Export Format
                            </x-admin::form.control-group.label>

                            <select 
                                name="format" 
                                class="w-full border border-gray-300 rounded-md px-3 py-2 text-gray-600 dark:text-gray-300 dark:border-gray-700 dark:bg-gray-900"
                            >
                                <option value="xlsx">XLSX</option>
                                <option value="csv">CSV</option>
                            </select>
                        </x-admin::form.control-group>
                    </div>

                    <div class="flex items-center gap-4">
                        <button
                            type="submit"
                            class="primary-button"
                        >
                            <span class="flex items-center gap-1.5">
                                <i class="icon-download text-xl"></i>
                                Export Products
                            </span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-admin::layouts>