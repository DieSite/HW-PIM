<div class="fixed top-14 h-full bg-white dark:bg-cherry-700 w-[270px] shadow-[0px_8px_10px_0px_rgba(0,0,0,0.2)] z-1000 max-lg:hidden transition-all duration-300 group-[.sidebar-collapsed]/container:w-[70px]">
    <div class="h-[calc(100vh-100px)] overflow-auto journal-scroll group-[.sidebar-collapsed]/container:overflow-visible">
        <nav class="grid gap-2 mt-2 w-full">
            <!-- Navigation Menu -->
            @foreach ($menu->items as $menuItem)
                <div
                    class="px-4 group/item {{ $menu->getActive($menuItem) ? 'active' : 'inactive' }}"
                    onmouseenter="adjustSubMenuPosition(event)"
                >
                    <a
                        href="{{ $menuItem['url'] }}"
                        class="flex gap-2.5 p-1.5 items-center cursor-pointer hover:rounded-lg {{ $menu->getActive($menuItem) == 'active' ? 'bg-violet-100 rounded-lg' : ' hover:bg-violet-50 dark:hover:bg-cherry-800' }} peer"
                    >
                        <span class="{{ $menuItem['icon'] }} text-2xl {{ $menu->getActive($menuItem) ? 'text-violet-700' : ''}}"></span>
                        
                        <p class="font-semibold whitespace-nowrap group-[.sidebar-collapsed]/container:hidden {{ $menu->getActive($menuItem) ? 'text-violet-700' : 'text-gray-600 dark:text-gray-300'}}">
                            @lang($menuItem['name'])
                        </p>
                    </a>

                    @if (count($menuItem['children']))
                        <div class="{{ $menu->getActive($menuItem) ? 'grid!' : '' }} hidden min-w-[180px] ltr:pl-10 rtl:pr-10 pb-2 rounded-b-lg z-100 overflow-hidden group-[.sidebar-collapsed]/container:hidden! group-[.sidebar-collapsed]/container:fixed ltr:group-[.sidebar-collapsed]/container:left-[70px]! rtl:group-[.sidebar-collapsed]/container:right-[70px]! group-[.sidebar-collapsed]/container:p-0 group-[.sidebar-collapsed]/container:bg-white dark:group-[.sidebar-collapsed]/container:bg-cherry-700 group-[.sidebar-collapsed]/container:border ltr:group-[.sidebar-collapsed]/container:rounded-r-lg rtl:group-[.sidebar-collapsed]/container:rounded-l-lg group-[.sidebar-collapsed]/container:border-gray-300 dark:group-[.sidebar-collapsed]/container:border-cherry-800 group-[.sidebar-collapsed]/container:rounded-none ltr:group-[.sidebar-collapsed]/container:shadow-[34px_10px_14px_rgba(0,0,0,0.01),19px_6px_12px_rgba(0,0,0,0.03),9px_3px_9px_rgba(0,0,0,0.04),2px_1px_5px_rgba(0,0,0,0.05),0px_0px_0px_rgba(0,0,0,0.05)] rtl:group-[.sidebar-collapsed]/container:shadow-[-34px_10px_14px_rgba(0,0,0,0.01),-19px_6px_12px_rgba(0,0,0,0.03),-9px_3px_9px_rgba(0,0,0,0.04),-2px_1px_5px_rgba(0,0,0,0.05),-0px_0px_0px_rgba(0,0,0,0.05)] group-hover/item:group-[.sidebar-collapsed]/container:grid! group-[.inactive]/item:hidden group-[.inactive]/item:fixed ltr:group-[.inactive]/item:left-[270px] rtl:group-[.inactive]/item:right-[270px] group-[.inactive]/item:p-0 group-[.inactive]/item:bg-white dark:group-[.inactive]/item:bg-cherry-700 group-[.inactive]/item:border ltr:group-[.inactive]/item:rounded-r-lg rtl:group-[.inactive]/item:rounded-l-lg group-[.inactive]/item:border-gray-300 dark:group-[.inactive]/item:border-cherry-800 group-[.inactive]/item:rounded-none ltr:group-[.inactive]/item:shadow-[34px_10px_14px_rgba(0,0,0,0.01),19px_6px_12px_rgba(0,0,0,0.03),9px_3px_9px_rgba(0,0,0,0.04),2px_1px_5px_rgba(0,0,0,0.05),0px_0px_0px_rgba(0,0,0,0.05)] rtl:group-[.inactive]/item:shadow-[-34px_10px_14px_rgba(0,0,0,0.01),-19px_6px_12px_rgba(0,0,0,0.03),-9px_3px_9px_rgba(0,0,0,0.04),-2px_1px_5px_rgba(0,0,0,0.05),-0px_0px_0px_rgba(0,0,0,0.05)] group-hover/item:group-[.inactive]/item:grid!">
                            @foreach ($menuItem['children'] as $subMenuItem)
                                <a
                                    href="{{ $subMenuItem['url'] }}"
                                    class="text-sm {{ $menu->getActive($subMenuItem) ? 'text-violet-700  dark:text-violet-400':'text-gray-600 dark:text-gray-300' }} whitespace-nowrap py-1 group-[.sidebar-collapsed]/container:px-5 group-[.sidebar-collapsed]/container:py-2.5 group-[.inactive]/item:px-5 group-[.inactive]/item:py-2.5 hover:text-violet-700 dark:hover:text-violet-400"
                                >
                                    @lang($subMenuItem['name'])
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        </nav>
    </div>

    <!-- Collapse menu -->
    <v-sidebar-collapse></v-sidebar-collapse>
</div>

@pushOnce('scripts')
    <script type="text/x-template" id="v-sidebar-collapse-template">
        <div
            class="bg-white dark:bg-cherry-700 fixed w-full max-w-[270px] bottom-0 px-4 hover:bg-violet-50 dark:hover:bg-cherry-800 border-t border-gray-200 dark:border-cherry-800  transition-all duration-300 cursor-pointer"
            :class="{'max-w-[70px]': isCollapsed}"
            @click="toggle"
        >
            <div class="flex gap-2.5 p-1.5 items-center">
                <span
                    class="icon-collapse transition-all text-2xl"
                    :class="[isCollapsed ? 'ltr:rotate-180 rtl:-rotate-0' : 'ltr:-rotate-0 rtl:rotate-180']"
                ></span>
            </div>
        </div>
    </script>

    <script type="module">
        app.component('v-sidebar-collapse', {
            template: '#v-sidebar-collapse-template',

            data() {
                return {
                    isCollapsed: {{ request()->cookie('sidebar_collapsed') ?? 0 }},
                }
            },

            methods: {
                toggle() {
                    this.isCollapsed = parseInt(this.isCollapsedCookie()) ? 0 : 1;

                    var expiryDate = new Date();

                    expiryDate.setMonth(expiryDate.getMonth() + 1);

                    document.cookie = 'sidebar_collapsed=' + this.isCollapsed + '; path=/; expires=' + expiryDate.toGMTString();

                    this.$root.$refs.appLayout.classList.toggle('sidebar-collapsed');
                },

                isCollapsedCookie() {
                    const cookies = document.cookie.split(';');

                    for (const cookie of cookies) {
                        const [name, value] = cookie.trim().split('=');

                        if (name === 'sidebar_collapsed') {
                            return value;
                        }
                    }
                    
                    return 0;
                },
            },
        });
    </script>

    <script>
        const adjustSubMenuPosition = (event) => {
            let menuContainer = event.currentTarget;

            let subMenuContainer = menuContainer.lastElementChild;

            if (subMenuContainer) {
                const menuTopOffset = menuContainer.getBoundingClientRect().top;

                const subMenuHeight = subMenuContainer.offsetHeight;

                const availableHeight = window.innerHeight - menuTopOffset;

                let subMenuTopOffset = menuTopOffset;

                if (subMenuHeight > availableHeight) {
                    subMenuTopOffset = menuTopOffset - (subMenuHeight - availableHeight);
                }

                subMenuContainer.style.top = `${subMenuTopOffset}px`;
            }
        };
    </script>
@endpushOnce