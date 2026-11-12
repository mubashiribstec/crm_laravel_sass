

import bootstrap from 'bootstrap/dist/js/bootstrap.min';

window.bootstrap = bootstrap;

import Prism from 'prismjs';
import NormalizeWhitespace from 'prismjs/plugins/normalize-whitespace/prism-normalize-whitespace.js'

import Swiper from "swiper";
import Inputmask from 'inputmask';
import ClipboardJS from 'clipboard';
import Gumshoe from 'gumshoejs/dist/gumshoe'
import Toastify from 'toastify-js'
import Choices from 'choices.js';
import  {Autoplay, EffectCreative, EffectFade, Mousewheel, Navigation, Pagination, Scrollbar} from 'swiper/modules'
import 'iconify-icon';
import 'simplebar'

// Components
class Components {
    initBootstrapComponents() {

        // Popovers
        const popoverTriggerList = document.querySelectorAll('[data-bs-toggle="popover"]')
        const popoverList = [...popoverTriggerList].map(popoverTriggerEl => new bootstrap.Popover(popoverTriggerEl))

        // Tooltips
        const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]')
        const tooltipList = [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl))

        // offcanvas
        const offcanvasElementList = document.querySelectorAll('.offcanvas')
        const offcanvasList = [...offcanvasElementList].map(offcanvasEl => new bootstrap.Offcanvas(offcanvasEl))

        //Toasts
        var toastPlacement = document.getElementById("toastPlacement");
        if (toastPlacement) {
            document.getElementById("selectToastPlacement").addEventListener("change", function () {
                if (!toastPlacement.dataset.originalClass) {
                    toastPlacement.dataset.originalClass = toastPlacement.className;
                }
                toastPlacement.className = toastPlacement.dataset.originalClass + " " + this.value;
            });
        }

        var toastElList = [].slice.call(document.querySelectorAll('.toast'))
        var toastList = toastElList.map(function (toastEl) {
            return new bootstrap.Toast(toastEl)
        })


        const alertTrigger = document.getElementById('liveAlertBtn')
        if (alertTrigger) {
            alertTrigger.addEventListener('click', () => {
                alert('Nice, you triggered this alert message!', 'success')
            })
        }

    }

    initfullScreenListener() {
        var fullScreenBtn = document.querySelector('[data-toggle="fullscreen"]');

        if (fullScreenBtn) {
            fullScreenBtn.addEventListener('click', function (e) {
                e.preventDefault();
                document.body.classList.toggle('fullscreen-enable')
                if (!document.fullscreenElement && /* alternative standard method */ !document.mozFullScreenElement && !document.webkitFullscreenElement) {
                    // current working methods
                    if (document.documentElement.requestFullscreen) {
                        document.documentElement.requestFullscreen();
                    } else if (document.documentElement.mozRequestFullScreen) {
                        document.documentElement.mozRequestFullScreen();
                    } else if (document.documentElement.webkitRequestFullscreen) {
                        document.documentElement.webkitRequestFullscreen(Element.ALLOW_KEYBOARD_INPUT);
                    }
                } else {
                    if (document.cancelFullScreen) {
                        document.cancelFullScreen();
                    } else if (document.mozCancelFullScreen) {
                        document.mozCancelFullScreen();
                    } else if (document.webkitCancelFullScreen) {
                        document.webkitCancelFullScreen();
                    }
                }
            });
        }
    }

    // App Search
    initAppSearch() {
        this.searchOption = document.getElementById('search-options');
        this.searchDropdown = document.getElementById('search-dropdown');
        this.searchClose = document.getElementById('search-close-options');
        const self = this;
        if (this.searchOption) {
            ['focus', 'keyup'].forEach(function (event) {
                self.searchOption.addEventListener(event, function (e) {
                    if (self.searchOption.value.length > 0) {
                        self.searchDropdown.classList.add('show');
                        self.searchClose.classList.remove('d-none');
                    } else {
                        self.searchDropdown.classList.remove('show');
                        self.searchClose.classList.add('d-none');
                    }
                })
            })
        }
        if (self.searchClose) {
            self.searchClose.addEventListener('click', function () {
                self.searchDropdown.classList.remove('show');
                self.searchClose.classList.add('d-none');
                self.searchOption.value = "";
            });
        }
    }

    // Counter Number
    initCounter() {
        var counter = document.querySelectorAll(".counter-value");
        var speed = 250; // The lower the slower
        counter &&
        counter.forEach(function (counter_value) {
            function updateCount() {
                var target = +counter_value.getAttribute("data-target");
                var count = +counter_value.innerText;
                var inc = target / speed;
                if (inc < 1) {
                    inc = 1;
                }
                // Check if target is reached
                if (count < target) {
                    // Add inc to count and output in counter_value
                    counter_value.innerText = (count + inc).toFixed(0);
                    // Call function every ms
                    setTimeout(updateCount, 1);
                } else {
                    counter_value.innerText = numberWithCommas(target);
                }
                numberWithCommas(counter_value.innerText);
            }

            updateCount();
        });

        function numberWithCommas(x) {
            return x.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
        }
    }

    init() {
        this.initBootstrapComponents();
        this.initfullScreenListener();
        this.initAppSearch();
        this.initCounter();
    }
}

// Form Validation ( Bootstrap )
class FormValidation {
    initFormValidation() {
        // Example starter JavaScript for disabling form submissions if there are invalid fields
        // Fetch all the forms we want to apply custom Bootstrap validation styles to
        // Loop over them and prevent submission
        document.querySelectorAll('.needs-validation').forEach(form => {
            form.addEventListener('submit', event => {
                if (!form.checkValidity()) {
                    event.preventDefault()
                    event.stopPropagation()
                }

                form.classList.add('was-validated')
            }, false)
        })
    }

    init() {
        this.initFormValidation();
    }
}

//  Form Advanced
class FormAdvanced {

    initMask() {
        document.querySelectorAll('[data-toggle="input-mask"]').forEach(e => {
            const maskFormat = e.getAttribute('data-mask-format').toString().replaceAll('0', '9');
            e.setAttribute("data-mask-format", maskFormat);
            const im = new Inputmask(maskFormat);
            im.mask(e);
        });
    }

    // Choices Select plugin
    initFormChoices() {
        var choicesExamples = document.querySelectorAll("[data-choices]");
        choicesExamples.forEach(function (item) {
            var choiceData = {};
            var isChoicesVal = item.attributes;
            if (isChoicesVal["data-choices-groups"]) {
                choiceData.placeholderValue = "This is a placeholder set in the config";
            }
            if (isChoicesVal["data-choices-search-false"]) {
                choiceData.searchEnabled = false;
            }
            if (isChoicesVal["data-choices-search-true"]) {
                choiceData.searchEnabled = true;
            }
            if (isChoicesVal["data-choices-removeItem"]) {
                choiceData.removeItemButton = true;
            }
            if (isChoicesVal["data-choices-sorting-false"]) {
                choiceData.shouldSort = false;
            }
            if (isChoicesVal["data-choices-sorting-true"]) {
                choiceData.shouldSort = true;
            }
            if (isChoicesVal["data-choices-multiple-remove"]) {
                choiceData.removeItemButton = true;
            }
            if (isChoicesVal["data-choices-limit"]) {
                choiceData.maxItemCount = isChoicesVal["data-choices-limit"].value.toString();
            }
            if (isChoicesVal["data-choices-limit"]) {
                choiceData.maxItemCount = isChoicesVal["data-choices-limit"].value.toString();
            }
            if (isChoicesVal["data-choices-editItem-true"]) {
                choiceData.maxItemCount = true;
            }
            if (isChoicesVal["data-choices-editItem-false"]) {
                choiceData.maxItemCount = false;
            }
            if (isChoicesVal["data-choices-text-unique-true"]) {
                choiceData.duplicateItemsAllowed = false;
                choiceData.paste = false;
            }
            if (isChoicesVal["data-choices-text-disabled-true"]) {
                choiceData.addItems = false;
            }
            isChoicesVal["data-choices-text-disabled-true"] ? new Choices(item, choiceData).disable() : new Choices(item, choiceData);
        });
    }

    init() {
        this.initMask();
        this.initFormChoices();
    }

}

// Card Portlet
class Portlet {
    constructor() {
        this.portletIdentifier = ".card";
        this.portletCloser = '.card a[data-toggle="remove"]';
        this.portletRefresher = '.card a[data-toggle="reload"]'
    }

    initCloser() {
        const self = this;
        document.querySelectorAll(this.portletCloser).forEach(element => {
            element.addEventListener('click', function (e) {
                e.preventDefault();
                const portlet = element.closest(self.portletIdentifier);
                const portlet_parent = portlet?.parentElement;
                if (portlet) portlet.remove();
                if (portlet_parent?.children.length === 0) portlet_parent?.remove();
                self.init();
            });
        });
    }

    initRefresher() {
        const self = this;
        const elements = document.querySelectorAll(this.portletRefresher);
        elements.forEach(function (element) {
            element.addEventListener('click', function (e) {
                e.preventDefault();
                const portlet = element.closest(self.portletIdentifier);
                if (portlet) portlet.innerHTML += ('<div class="card-disabled"><div class="card-portlets-loader"></div></div>');
                let pd;
                portlet?.children.forEach(element => {
                    if (element.classList.contains('card-disabled')) pd = element;
                });
                setTimeout(function () {
                    pd?.remove();
                    self.init();
                }, 500 + 300 * (Math.random() * 5));
            })
        });
    }

    init = () => {
        this.initRefresher();
        this.initCloser();
    }
}

// Code Highlight and Copy ( Clipboard ) and Components nav Link Active
class Code {

    initCode() {

        let elements = document.querySelectorAll('.highlight');

        if (elements && elements.length > 0) {
            for (var i = 0; i < elements.length; ++i) {
                var highlight = elements[i];
                var copy = highlight.querySelector('.btn-copy-clipboard');

                if (copy) {
                    var clipboard = new ClipboardJS(copy, {
                        target: function (trigger) {
                            var highlight = trigger.closest('.highlight');
                            var el = highlight.querySelector('.tab-pane.active');

                            if (el == null) {
                                el = highlight.querySelector('.code');
                            }

                            return el;
                        }
                    });

                    clipboard.on('success', function (e) {
                        var caption = e.trigger.innerHTML;

                        e.trigger.innerHTML = 'Copied';
                        e.clearSelection();

                        setTimeout(function () {
                            e.trigger.innerHTML = caption;
                        }, 2000);
                    });
                }
            }
        }

        Prism.plugins.NormalizeWhitespace.setDefaults({
            'remove-trailing': true,
            'remove-indent': true,
            'left-trim': true,
            'right-trim': true,
        });

        // Gumshoe (Link Active)
        if (document.querySelector('.docs-nav a')) {
            new Gumshoe('.docs-nav a');
        }

    }

    init() {
        this.initCode();
    }
}

// Dragula (Draggable Components)
class Dragula {

    initDragula() {

        document.querySelectorAll("[data-plugin=dragula]")

            .forEach(function (element) {

                const containersIds = JSON.parse(element.getAttribute('data-containers'));
                let containers = [];
                if (containersIds) {
                    for (let i = 0; i < containersIds.length; i++) {
                        containers.push(document.querySelectorAll("#" + containersIds[i])[0]);
                    }
                } else {
                    containers = [element];
                }

                // if handle provided
                const handleClass = element.getAttribute('data-handleclass');

                // init dragula
                if (handleClass) {
                    dragula(containers, {
                        moves: function (el, container, handle) {
                            return handle.classList.contains(handleClass);
                        }
                    });
                } else {
                    dragula(containers);
                }

            });

    }

    init() {
        this.initDragula();
    }


}

// Swiper Slider
class SwiperSlider {
    initSwiperSlider() {

        //Default Swiper
        var swiper = new Swiper("[data-swiper='default']", {
            modules: [Autoplay],
            loop: true,
            autoplay: {
                delay: 2500,
                disableOnInteraction: false,
            },
        });

        //Navigation Swiper
        var swiper = new Swiper("[data-swiper='navigation']", {
            modules: [Autoplay, Navigation, Pagination],
            loop: true,
            autoplay: {
                delay: 2500,
                disableOnInteraction: false,
            },
            navigation: {
                nextEl: ".swiper-button-next",
                prevEl: ".swiper-button-prev",
            },
            pagination: {
                clickable: true,
                el: ".swiper-pagination",
            },
        });

        //Pagination Dynamic Swiper
        var swiper = new Swiper("[data-swiper='dynamic']", {
            modules: [Autoplay, Pagination],
            loop: true,
            autoplay: {
                delay: 2500,
                disableOnInteraction: false,
            },
            pagination: {
                clickable: true,
                el: ".swiper-pagination",
                dynamicBullets: true,
            },
        });

        // Pagination fraction Swiper
        var swiper = new Swiper("[data-swiper='fraction']", {
            modules: [Autoplay, Pagination, Navigation],
            loop: true,
            autoplay: {
                delay: 2500,
                disableOnInteraction: false,
            },
            pagination: {
                clickable: true,
                el: ".swiper-pagination",
                type: 'fraction',
            },
            navigation: {
                nextEl: ".swiper-button-next",
                prevEl: ".swiper-button-prev",
            },
        });

        // Pagination Custom Swiper
        var swiper = new Swiper("[data-swiper='custom']", {
            modules: [Autoplay,Pagination],
            loop: true,
            autoplay: {
                delay: 2500,
                disableOnInteraction: false,
            },
            pagination: {
                clickable: true,
                el: ".swiper-pagination",
                renderBullet: function (index, className) {
                    return '<span class="' + className + '">' + (index + 1) + "</span>";
                },
            }
        });

        // Pagination Progress Swiper
        var swiper = new Swiper("[data-swiper='progress']", {
            modules: [Autoplay, Pagination, Navigation],
            loop: true,
            autoplay: {
                delay: 2500,
                disableOnInteraction: false,
            },
            pagination: {
                el: ".swiper-pagination",
                type: 'progressbar',
            },
            navigation: {
                nextEl: ".swiper-button-next",
                prevEl: ".swiper-button-prev",
            },
        });

        // Scrollbar Swiper
        var swiper = new Swiper("[data-swiper='scrollbar']", {
            modules: [Autoplay, Scrollbar, Navigation],
            loop: true,
            autoplay: {
                delay: 2500,
                disableOnInteraction: false,
            },
            scrollbar: {
                el: ".swiper-scrollbar",
                hide: true,
            },
            navigation: {
                nextEl: ".swiper-button-next",
                prevEl: ".swiper-button-prev",
            }
        });

        // Vertical Swiper
        var swiper = new Swiper("[data-swiper='vertical']", {
            modules: [Autoplay, Pagination],
            loop: true,
            direction: 'vertical',
            autoplay: {
                delay: 2500,
                disableOnInteraction: false,
            },
            pagination: {
                el: ".swiper-pagination",
                clickable: true,
            },
        });

        // Mousewheel Control Swiper
        var swiper = new Swiper("[data-swiper='mousewheel']", {
            modules: [Mousewheel, Autoplay, Pagination],
            loop: true,
            direction: 'vertical',
            mousewheel: true,
            autoplay: {
                delay: 2500,
                disableOnInteraction: false,
            },
            pagination: {
                el: ".swiper-pagination",
                clickable: true,
            },
        });

        // Effect Fade Swiper
        var swiper = new Swiper("[data-swiper='effect-fade']", {
            modules: [Autoplay, Pagination, EffectFade],
            loop: true,
            effect: 'fade',
            autoplay: {
                delay: 2500,
                disableOnInteraction: false,
            },
            pagination: {
                el: ".swiper-pagination",
                clickable: true,
            },
        });

        // Effect Coverflow Swiper
        var swiper = new Swiper("[data-swiper='coverflow']", {
            modules: [Autoplay, Pagination],
            loop: true,
            effect: 'coverflow',
            grabCursor: true,
            centeredSlides: true,
            slidesPerView: "4",
            coverflowEffect: {
                rotate: 50,
                stretch: 0,
                depth: 100,
                modifier: 1,
                slideShadows: true,
            },
            autoplay: {
                delay: 2500,
                disableOnInteraction: false,
            },
            pagination: {
                el: ".swiper-pagination",
                clickable: true,
                dynamicBullets: true,
            },
        });

        // Effect Flip Swiper
        var swiper = new Swiper("[data-swiper='flip']", {
            modules: [Autoplay, Pagination],
            loop: true,
            effect: 'flip',
            grabCursor: true,
            autoplay: {
                delay: 2500,
                disableOnInteraction: false,
            },
            pagination: {
                el: ".swiper-pagination",
                clickable: true,
            },
        });

        // Effect Creative Swiper
        var swiper = new Swiper("[data-swiper='creative']", {
            modules: [EffectCreative, Autoplay, Pagination],
            loop: true,
            grabCursor: true,
            effect: 'creative',
            creativeEffect: {
                prev: {
                    shadow: true,
                    translate: [0, 0, -400],
                },
                next: {
                    translate: ["100%", 0, 0],
                },
            },
            autoplay: {
                delay: 2500,
                disableOnInteraction: false,
            },
            pagination: {
                el: ".swiper-pagination",
                clickable: true,
            },
        });

        // Responsive Swiper
        var swiper = new Swiper("[data-swiper='responsive']", {
            modules: [Pagination],
            loop: true,
            slidesPerView: 1,
            spaceBetween: 10,
            pagination: {
                el: ".swiper-pagination",
                clickable: true,
            },
            breakpoints: {
                768: {
                    slidesPerView: 2,
                    spaceBetween: 40,
                },
                1200: {
                    slidesPerView: 3,
                    spaceBetween: 50,
                },
            },
        });
    }

    init() {
        this.initSwiperSlider();
    }
}

// Toast Notification
class ToastNotification {
    initToastNotification() {

        document.querySelectorAll("[data-toast]").forEach(function (element) {
            element.addEventListener("click", function () {
                var toastData = {};
                if (element.attributes["data-toast-text"]) {
                    toastData.text = element.attributes["data-toast-text"].value.toString();
                }
                if (element.attributes["data-toast-gravity"]) {
                    toastData.gravity = element.attributes["data-toast-gravity"].value.toString();
                }
                if (element.attributes["data-toast-position"]) {
                    toastData.position = element.attributes["data-toast-position"].value.toString();
                }
                if (element.attributes["data-toast-className"]) {
                    toastData.className = element.attributes["data-toast-className"].value.toString();
                }
                if (element.attributes["data-toast-duration"]) {
                    toastData.duration = element.attributes["data-toast-duration"].value.toString();
                }
                if (element.attributes["data-toast-close"]) {
                    toastData.close = element.attributes["data-toast-close"].value.toString();
                }
                if (element.attributes["data-toast-style"]) {
                    toastData.style = element.attributes["data-toast-style"].value.toString();
                }
                if (element.attributes["data-toast-offset"]) {
                    toastData.offset = element.attributes["data-toast-offset"];
                }
                Toastify({
                    newWindow: true,
                    text: toastData.text,
                    gravity: toastData.gravity,
                    position: toastData.position,
                    className: "bg-" + toastData.className,
                    stopOnFocus: true,
                    offset: {
                        x: toastData.offset ? 50 : 0, // horizontal axis - can be a number or a string indicating unity. eg: '2em'
                        y: toastData.offset ? 10 : 0, // vertical axis - can be a number or a string indicating unity. eg: '2em'
                    },
                    duration: toastData.duration,
                    close: toastData.close == "close" ? true : false,
                }).showToast();
            });
        });
    }

    init() {
        this.initToastNotification();
    }
}

document.addEventListener('DOMContentLoaded', function () {
    new Components().init();
    new FormValidation().init();
    new FormAdvanced().init();
    new Portlet().init();
    new Code().init();
    new Dragula().init();
    new SwiperSlider().init();
    new ToastNotification().init();

    // Only run if route is available
    if (window.laravelRoutes && window.laravelRoutes.unreadMessages) {
        fetchUnreadMessages();
        setInterval(fetchUnreadMessages, 20000);  // Every 20 seconds
    }

    if (window.laravelRoutes && window.laravelRoutes.unreadNotifications) {
        fetchUnreadNotifications();
        setInterval(fetchUnreadNotifications, 20000);  // Every 20 seconds
    }
});

// Function to fetch unread messages
function fetchUnreadMessages() {
    $.ajax({
        url: window.laravelRoutes.unreadMessages,
        method: 'GET',
        success: function (response) {
            console.log(response);  // Log the full response to verify the structure
            if (response.success) {
                // Update unread count for messages
                $('#unread-message-count').text(response.unread_count || 0);

                // Clear the message list before populating new messages
                $('#message-items').empty();

                if (response.messages.length === 0) {
                    $('#message-items').append('<div class="text-center py-3 text-muted">No new messages</div>');
                } else {
                    response.messages.forEach(function (message) {
                        const messagesIndexUrl = "/messages";
                        const html = `
                            <a href="${messagesIndexUrl}" class="dropdown-item py-3 border-bottom text-wrap">
                                <div class="d-flex">
                                    <div class="flex-shrink-0">
                                        <img src="${message.avatar}" class="img-fluid me-2 avatar-sm rounded-circle" alt="user-avatar" />
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="mb-0">
                                            <span class="fw-medium">${message.user_name}</span><br>
                                            <span>${message.message}</span>
                                        </p>
                                        <small class="text-muted">${message.created_at}</small>
                                    </div>
                                </div>
                            </a>`;
                        $('#message-items').append(html);
                    });
                }
            } else {
                console.log('Error fetching messages:', response.error);
            }
        },
        error: function (xhr) {
            console.log('AJAX error:', xhr.responseText);
        }
    });
}

// Function to fetch unread notifications
function fetchUnreadNotifications() {
    $.ajax({
        url: window.laravelRoutes.unreadNotifications,
        method: 'GET',
        success: function (response) {
            console.log(response);  // Log the full response to verify the structure
            
            if (response.success) {
                // Update unread count for notifications
                $('#notification-count').text(response.unread_count || 0);

                // Clear the notification list before populating new notifications
                $('#notification-items').empty();

                // Check if there are unread notifications
                if (response.notifications.length === 0) {
                    $('#notification-items').append('<div class="text-center py-3 text-muted">No new notifications</div>');
                    // Remove the pulse animation if no unread notifications
                    // $('#page-header-notifications-dropdown').removeClass('unread-notifications');
                } else {
                    response.notifications.forEach(function (notification) {
                        const html = `
                            <a href="/notifications" class="dropdown-item py-3 border-bottom text-wrap" data-bs-toggle="modal" data-bs-target="#notificationModal" data-notification-id="${notification.id}">
                                <div class="d-flex">
                                    <div class="flex-shrink-0">
                                        <!-- Use an Icon instead of an Image -->
                                        <iconify-icon icon="ic:round-notifications" class="fs-24 text-primary"></iconify-icon>
                                    </div>
                                    <div class="flex-grow-1">
                                        <p class="mb-0">
                                            <span class="fw-medium">${notification.user_name}</span><br>
                                            <span>${notification.message}</span>
                                        </p>
                                        <small class="text-muted">${notification.created_at}</small>
                                    </div>
                                </div>
                            </a>`;
                        $('#notification-items').append(html);
                    });

                    // Add the class to trigger animation (pulse)
                    // $('#page-header-notifications-dropdown').addClass('unread-notifications');
                }
            } else {
                console.log('Error fetching notifications:', response.error);
            }
        },
        error: function (xhr) {
            console.log('AJAX error:', xhr.responseText);
        }
    });
}

document.addEventListener('DOMContentLoaded', function () {
    // Initialize offcanvas
    const chatOffcanvas = new bootstrap.Offcanvas(document.getElementById('chatOffcanvas'));
    const emailOffcanvas = new bootstrap.Offcanvas(document.getElementById('emailOffcanvas'));

    // Handle chat button clicks
    $(document).on('click', '.chat-btn', function() {
        const applicantId = $(this).data('applicant-id');
        const phone = $(this).data('phone');
        const name = $(this).data('name');

        // Set chat header info
        $('#chatUserName').text(name);
        $('#chatUserPhone').text(phone);
        $('#applicantId').val(applicantId);

        // Load messages
        loadMessages(applicantId, phone);

        // Show offcanvas
        chatOffcanvas.show();
    });

    $(document).on('click', '.email-btn', function() {
        const action = $(this);
        const applicantId = action.data('applicant-id');
        const email = action.data('email');
        const secondaryEmail = action.data('secondary-email');
        const name = action.data('name');
        const emails = [email, secondaryEmail]
            .filter(value => typeof value === 'string' && value.trim() !== '')
            .map(value => value.trim())
            .filter((value, index, values) => values.indexOf(value) === index);
        const emailTo = $('#emailTo');
        const senderName = String($('#emailForm').data('sender-pseudo-name') || '').trim();
        const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, character => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        })[character]);
        const renderRichText = value => {
            const allowedTags = new Set(['B', 'BR', 'DIV', 'EM', 'I', 'LI', 'OL', 'P', 'STRONG', 'U', 'UL']);
            const blockedTags = new Set(['SCRIPT', 'STYLE', 'IFRAME', 'OBJECT', 'SVG', 'MATH']);
            const parsed = new DOMParser().parseFromString(String(value ?? ''), 'text/html');
            const cleanChildren = (source, target) => {
                Array.from(source.childNodes).forEach(node => {
                    if (node.nodeType === Node.TEXT_NODE) {
                        target.appendChild(document.createTextNode(node.nodeValue || ''));
                    } else if (node.nodeType === Node.ELEMENT_NODE && !blockedTags.has(node.tagName)) {
                        if (allowedTags.has(node.tagName)) {
                            const element = document.createElement(node.tagName.toLowerCase());
                            cleanChildren(node, element);
                            target.appendChild(element);
                        } else {
                            cleanChildren(node, target);
                        }
                    }
                });
            };
            const clean = document.createElement('div');
            cleanChildren(parsed.body, clean);
            return clean.innerHTML;
        };
        const rowValue = key => {
            const value = action.data(key);
            return value === undefined || value === null || String(value).trim() === ''
                ? '-'
                : String(value).trim();
        };
        const jobTitle = String(action.data('job-title') || '').trim();

        emailTo.empty().append($('<option>', {
            value: '',
            text: 'Select applicant email'
        }));
        emails.forEach(address => {
            emailTo.append($('<option>', {
                value: address,
                text: address
            }));
        });
        emailTo.val('').trigger('change');

        $('#emailUserName').text(name);
        $('#emailUserEmail').text(emails.join(' / ') || 'No email address available');
        $('#emailapplicantId').val(applicantId);
        $('#emailSaleId').val(action.data('sale-id') || '');
        const defaultTitle = [jobTitle, senderName].filter(Boolean).join(' - ');
        $('#emailTitle').val(defaultTitle);
        $('#emailSubject').val(defaultTitle);

        const detailRows = [
            ['Head Office', rowValue('office-name')],
            ['Unit/Branch', rowValue('unit-name')],
            ['Position Type', rowValue('position-type')],
            ['Benefits', rowValue('benefits')],
            ['Salary', rowValue('salary')],
            ['Timings', rowValue('timing')],
            ['Experience', rowValue('experience')],
            ['Qualification', rowValue('qualification')],
            ['Location', rowValue('location')]
        ].map(([label, value]) => `
            <tr>
                <td style="padding:7px 12px;border-bottom:1px solid #e8edf2;color:#566273;font-weight:600;white-space:nowrap;">${escapeHtml(label)}</td>
                <td style="padding:7px 12px;border-bottom:1px solid #e8edf2;color:#263445;">${renderRichText(value)}</td>
            </tr>
        `).join('');
        const $emailForm = $('#emailForm');
        const logoUrl = escapeHtml($emailForm.data('logo-url') || '');
        const imagePath = String($emailForm.attr('data-signature-image') || '').trim();
        const signatureImageUrl = imagePath
            ? imagePath.startsWith('uploads/')
                ? `${window.location.origin}/${imagePath}`
                : `${window.location.origin}/storage/${imagePath}`
            : logoUrl;
        const signatureCompanyName = escapeHtml($emailForm.attr('data-signature-company-name') || 'Kingsbury Personnel');
        const signaturePhone = escapeHtml($emailForm.attr('data-signature-phone') || '+44 (0) 1494 418312');
        const signatureEmail = escapeHtml($emailForm.attr('data-signature-email') || '');
        const body = `
            <p>Hi ${escapeHtml(name || 'Applicant')},</p>
            <p>Hope you are doing well. Kindly have a look over the details below and let me know so that we may proceed with sharing your CV for shortlisting.</p>
            <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;max-width:620px;border:1px solid #e8edf2;border-collapse:collapse;font-family:Arial,sans-serif;font-size:14px;">
                <tbody>${detailRows}</tbody>
            </table>
            <p style="margin-top:16px;">Kindly reply as soon as possible with <strong>YES</strong> and your interview availability so we can proceed with your CV. I look forward to your confirmation.</p>
            <table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:24px;padding-top:28px;border-top:2px solid #c9a45c;font-family:Arial,sans-serif;">
                <tbody><tr>
                    <td style="padding:0 18px 0 0;border-right:1px solid #e8edf2;vertical-align:middle;">
                        <img src="${escapeHtml(signatureImageUrl)}" alt="${signatureCompanyName}" width="100" style="display:block;width:100px;max-width:100px;height:auto;">
                    </td>
                    <td style="padding:0 0 0 18px;vertical-align:middle;">
                        <p style="margin:0 0 5px;color:#566273;font-size:13px;">Warm regards,</p>
                        <p style="margin:0;color:#263445;font-size:16px;font-weight:700;">${escapeHtml(senderName)}</p>
                        <p style="margin:4px 0 0;color:#a27c38;font-size:12px;letter-spacing:1px;text-transform:uppercase;">${signatureCompanyName}</p>
                        <p style="margin:4px 0 0;color:#a27c38;font-size:12px;letter-spacing:1px;text-transform:uppercase;">Phone: ${signaturePhone}</p>
                        <p style="margin:4px 0 0;color:#a27c38;font-size:12px;letter-spacing:1px;text-transform:uppercase;">${signatureEmail}</p>
                    </td>
                </tr></tbody>
            </table>
        `;
        $('#emailStatus').empty();
        $('#sendEmailBtn').prop('disabled', false);
        if ($('#emailBody').next('.note-editor').length) {
            $('#emailBody').summernote('code', body);
        } else {
            $('#emailBody').val(body);
        }

        emailOffcanvas.show();
    });

    $(document).on('change', '#emailTo', function() {
        $('#emailUserEmail').text($(this).val() || 'No email address available');
    });

    // Function to load messages
    function loadMessages(applicantId, phone) {
        $('#messagesContainer').html(`
                <div class="text-center py-5">
                <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                </div>
                </div>
            `);

        // Get CSRF token from meta tag
        const csrfToken = $('meta[name="csrf-token"]').attr('content');

        $.ajax({
            url: '/getChatBoxMessages',
            method: 'POST',
            data: {
                recipient_id: applicantId,
                recipient_type: 'applicant',
                _token: csrfToken,  // Use the token from meta tag
                list_ref : 'applicant'
            },
            success: function(response) {
                const messages = response.messages;
                const recipient = response.recipient;
                let messagesHtml = '';
                let recipientPhone = recipient.phone ?? '';

                if (!messages || messages.length === 0) {
                    // Show "No Chat Available" message if no messages
                    $('#noChatMessage').show();
                } else {
                    messages.forEach(message => {
                        const isSender = message.is_sender;
                        const avatar = isSender ? '/images/users/avatar-1.jpg' : `/images/users/avatar-${recipient.id % 10 || 1}.jpg`;
                        const sendStatusIcon = message.is_sent ? '<i class="ri-check-double-line fs-18 text-info"></i>' : '<i class="ri-check-line fs-18 text-muted"></i>';
                        const receiveStatusIcon = message.is_read ? '<i class="ri-check-double-line fs-18 text-info"></i>' : '<i class="ri-check-line fs-18 text-muted"></i>';
                    
                        if (message.status == 'Sent') {
                            messagesHtml += `
                                <li class="d-flex gap-2 clearfix justify-content-end odd">
                                    <div class="chat-conversation-text ms-0">
                                        <div>
                                            <p class="mb-2 fs-12"><span class="text-dark fw-medium me-1">${isSender ? 'You' : message.user_name}</span> ${message.created_at}</p>
                                        </div>
                                        <div class="d-flex justify-content-end">
                                            <div class="incoming">
                                                <p>${message.message}</p>
                                            </div>
                                        </div>
                                        <div class="d-flex justify-content-end align-items-center"><small class="text-muted">${message.phone_number || ''} | ${message.status}</small>&nbsp;&nbsp;<span>${sendStatusIcon}</span></div>
                                    </div>
                                    <div class="chat-avatar text-center">
                                        <img src="${avatar}" alt="" class="avatar rounded-circle">
                                    </div>
                                </li>
                            `;
                        } else {
                            messagesHtml += `
                                <li class="d-flex gap-2 clearfix justify-content-start even">
                                    <div class="chat-avatar text-center">
                                        <img src="/images/users/avatar-${recipient.id % 5 || 1}.jpg" alt="avatar-${recipient.id % 5 || 1}" class="avatar rounded-circle">
                                    </div>
                                    <div class="chat-conversation-text ms-0">
                                        <div>
                                            <p class="mb-2 fs-12"><span class="text-dark fw-medium me-1"><em class="text-muted">Replied</em> </span> ${message.created_at}</p>
                                        </div>
                                        <div class="d-flex align-items-start">
                                            <div class="outgoing">
                                                <p>${message.message}</p>
                                            </div>
                                        </div>
                                        <div class="d-flex justify-content-end align-items-center"><small class="text-muted">${message.phone_number || ''} | Seen </small>&nbsp;&nbsp;<span>${receiveStatusIcon}</span></div>
                                    </div>
                                </li>
                            `;
                        }
                    });
                }

                $('#messagesContainer').html(messagesHtml);

                // ✅ Scroll to bottom after messages render
                setTimeout(scrollToBottom, 100);
            },
            error: function(xhr) {
                console.error('Error loading messages:', xhr);
            }
        });
    }

    // Handle message submission
    $('#messageForm').submit(function(e) {
        e.preventDefault();

        const message = $('#messageInput').val().trim();
        const applicantId = $('#applicantId').val();
        const phone = $('#chatUserPhone').text();

        // Get CSRF token from meta tag
        const csrfToken = $('meta[name="csrf-token"]').attr('content');

        if (message) {
            $.ajax({
                url: "/sendChatBoxMsg",
                method: 'POST',
                data: {
                    recipient_id: applicantId,
                    recipient_type: 'applicant',
                    recipient_phone: phone,
                    message: message,
                    _token: csrfToken,  // Use the token from meta tag
                },
                success: function(response) {
                    $('#messageInput').val('');
                    // Add the sent message to the chat
                    const newMessageHtml = `
                        <div class="d-flex justify-content-end mb-3">
                            <div class="bg-primary text-white p-3 rounded-3" style="max-width: 75%">
                            <p class="mb-1">${message}</p>
                            <small class="d-block text-end text-white-50">Just now</small>
                            </div>
                        </div>
                        `;
                    $('#messagesContainer').append(newMessageHtml);
                    $('#messagesContainer').scrollTop($('#messagesContainer')[0]
                        .scrollHeight);
                    $('#noMessages').hide();
                }
            });
        }
    });

    $('#emailForm').submit(function(e) {
        e.preventDefault();

        const applicantId = $('#emailapplicantId').val();
        const saleId = $('#emailSaleId').val();
        const fromEmail = $('#fromEmail').val().trim();
        const toEmail = $('#emailTo').val().trim();
        const title = $('#emailTitle').val().trim();
        const subject = $('#emailSubject').val().trim();
        const message = $('#emailBody').summernote('code').trim(); // get HTML content from Summernote
        const csrfToken = $('meta[name="csrf-token"]').attr('content');

        if (fromEmail && toEmail && title && subject && message && message !== '<p><br></p>') {
            $.ajax({
                url: "/saveComposedEmail",
                method: "POST",
                data: {
                    applicant_id: applicantId,
                    sale_id: saleId,
                    app_email: toEmail,
                    from_email: fromEmail,
                    email_title: title,
                    email_subject: subject,
                    email_body: message,
                    _token: csrfToken
                },
                beforeSend: function() {
                    $('#emailStatus').html('<span class="text-primary"><i class="ri-loader-4-line spin me-1"></i> Sending...</span>');
                    $('#sendEmailBtn').prop('disabled', true);
                },
                success: function(response) {
                    $('#sendEmailBtn').prop('disabled', false);
                    if (response.success) {
                        const successMessage = response.message || 'Email queued successfully.';
                        $('#emailStatus').html(`<span class="text-success">${successMessage}</span>`);
                        toastr.success(successMessage);
                        $('#emailTitle').val('');
                        $('#emailSubject').val('');
                        $('#emailBody').summernote('reset'); // clear Summernote editor
                        setTimeout(() => {
                            $('#emailStatus').text('');

                            // Close the offcanvas
                            const offcanvasEl = document.getElementById('emailOffcanvas'); // <-- your offcanvas ID
                            const offcanvas = bootstrap.Offcanvas.getInstance(offcanvasEl);
                            if (offcanvas) {
                                offcanvas.hide();
                            }
                        }, 1500);
                    } else {
                        $('#emailStatus').html('<span class="text-danger">❌ Failed to send email.</span>');
                    }
                },
                error: function(xhr) {
                    $('#sendEmailBtn').prop('disabled', false);
                    const errorMsg = xhr.responseJSON?.message || 'Something went wrong.';
                    $('#emailStatus').html(`<span class="text-danger">⚠️ Error: ${errorMsg}</span>`);
                }
            });
        } else {
            $('#emailStatus').html('<span class="text-warning">⚠️ Please fill in all fields before sending.</span>');
        }
    });

    function scrollToBottom() {
        const container = $('#messagesContainer');
        container.scrollTop(container[0].scrollHeight);
    }

    $('#emailOffcanvas').on('shown.bs.offcanvas', function () {
        const editor = $('#emailBody');
        if (editor.next('.note-editor').length) {
            return;
        }

        if (typeof $.fn.summernote !== 'function') {
            console.error('Summernote is required to edit email content.');
            return;
        }

        editor.summernote({
            height: 250,
            toolbar: [
                ['style', ['bold', 'italic', 'underline', 'clear']],
                ['font', ['strikethrough', 'superscript', 'subscript']],
                ['fontsize', ['fontsize']],
                ['color', []],
                ['para', ['ul', 'ol', 'paragraph']],
                ['insert', ['link']],
                ['view', ['codeview']]
            ]
        });
    });

});