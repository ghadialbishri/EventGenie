function initializeNavbarMenus() {
    const profileBtn = document.getElementById('profile-menu-button');
    const profileDropdown = document.getElementById('profile-dropdown');

    if (profileBtn && profileDropdown) {
        profileBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            profileDropdown.classList.toggle('hidden');
        });

        document.addEventListener('click', (e) => {
            if (!profileDropdown.contains(e.target) && !profileBtn.contains(e.target)) {
                profileDropdown.classList.add('hidden');
            }
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                profileDropdown.classList.add('hidden');
            }
        });
    }

    const menuBtn = document.getElementById('mobile-menu-button');
    const mobileMenu = document.getElementById('mobile-menu');

    if (menuBtn && mobileMenu) {
        menuBtn.addEventListener('click', () => {
            mobileMenu.classList.toggle('hidden');
        });
    }
}

// Function to show navigation based on user role
function showRoleBasedNavigation() {
    const token = localStorage.getItem('token');
    const userData = localStorage.getItem('user');

    // Hide all navigation sections first
    const navSections = ['host-nav', 'guest-nav', 'admin-nav'];
    const mobileNavSections = ['mobile-host-nav', 'mobile-guest-nav', 'mobile-admin-nav'];
    
    navSections.forEach(id => {
        const element = document.getElementById(id);
        if (element) element.classList.add('hidden');
    });
    
    mobileNavSections.forEach(id => {
        const element = document.getElementById(id);
        if (element) element.classList.add('hidden');
    });

    // Show profile menu by default, will hide for admin if needed
    const profileMenu = document.getElementById('profile-menu-button');
    if (profileMenu) {
        profileMenu.style.display = '';
    }
    const profileDropdown = document.getElementById('profile-dropdown');
    if (profileDropdown) {
        profileDropdown.classList.add('hidden');
    }
    
    // Hide authentication buttons by default (will show if not logged in on public pages)
    const authButtonsNav = document.getElementById('auth-buttons-nav');
    const mobileAuthButtonsNav = document.getElementById('mobile-auth-buttons-nav');
    if (authButtonsNav) {
        authButtonsNav.classList.add('hidden');
    }
    if (mobileAuthButtonsNav) {
        mobileAuthButtonsNav.classList.add('hidden');
    }

    if (!token || !userData) {
        // No token or user data - hide all role-based navigation
        // Don't show any navigation on public pages like index.html
        const currentPath = window.location.pathname;
        const isPublicPage = currentPath.endsWith('index.html') || 
                            currentPath.endsWith('login.html') || 
                            currentPath.endsWith('signup.html') ||
                            currentPath.endsWith('venues.html') ||
                            (currentPath.endsWith('/') && !currentPath.includes('/admin/') && !currentPath.includes('/host/') && !currentPath.includes('/guest/'));
        
        if (isPublicPage) {
            // On public pages, hide all role-based navigation
            const navSections = ['host-nav', 'guest-nav', 'admin-nav'];
            const mobileNavSections = ['mobile-host-nav', 'mobile-guest-nav', 'mobile-admin-nav'];
            
            navSections.forEach(id => {
                const element = document.getElementById(id);
                if (element) element.classList.add('hidden');
            });
            
            mobileNavSections.forEach(id => {
                const element = document.getElementById(id);
                if (element) element.classList.add('hidden');
            });
            
            // Hide profile menu on public pages when not logged in
            if (profileMenu) {
                profileMenu.style.display = 'none';
            }
            
            // Hide mobile profile links
            const mobileProfileLinks = document.querySelectorAll('#mobile-menu a[href*="my_profile.html"], #mobile-menu a[href*="help_support.html"], #mobile-menu a[href*="logout"]');
            mobileProfileLinks.forEach(link => {
                link.style.display = 'none';
            });
            
            // Show authentication buttons on public pages when not logged in
            const authButtonsNav = document.getElementById('auth-buttons-nav');
            const mobileAuthButtonsNav = document.getElementById('mobile-auth-buttons-nav');
            if (authButtonsNav) {
                authButtonsNav.classList.remove('hidden');
                // Fix login/signup links based on current location
                const loginLink = document.getElementById('nav-login');
                const signupLink = document.getElementById('nav-signup');
                if (loginLink && currentPath.endsWith('index.html')) {
                    loginLink.href = 'login.html';
                }
                if (signupLink && currentPath.endsWith('index.html')) {
                    signupLink.href = 'signup.html';
                }
            }
            if (mobileAuthButtonsNav) {
                mobileAuthButtonsNav.classList.remove('hidden');
                // Fix mobile login/signup links based on current location
                const mobileLoginLink = document.getElementById('mobile-nav-login');
                const mobileSignupLink = document.getElementById('mobile-nav-signup');
                if (mobileLoginLink && currentPath.endsWith('index.html')) {
                    mobileLoginLink.href = 'login.html';
                }
                if (mobileSignupLink && currentPath.endsWith('index.html')) {
                    mobileSignupLink.href = 'signup.html';
                }
            }
            
            return;
        } else {
            // On role-specific pages but not logged in, show guest navigation as fallback
            const guestNav = document.getElementById('guest-nav');
            const mobileGuestNav = document.getElementById('mobile-guest-nav');
            if (guestNav) guestNav.classList.remove('hidden');
            if (mobileGuestNav) mobileGuestNav.classList.remove('hidden');
            
            // Update profile links for guest (default)
            updateProfileLinks('guest');
            return;
        }
    }

    // Get user role from stored user data
    try {
        const user = JSON.parse(userData);
        const role = user.role || 'guest';

        if (role === 'admin') {
            const adminNav = document.getElementById('admin-nav');
            const mobileAdminNav = document.getElementById('mobile-admin-nav');
            if (adminNav) adminNav.classList.remove('hidden');
            if (mobileAdminNav) mobileAdminNav.classList.remove('hidden');
            
            // Update admin navigation links to use correct paths
            updateAdminNavLinks();
            
            // Hide profile menu for admin (they have logout in nav)
            if (profileMenu) {
                profileMenu.style.display = 'none';
            }
            // Hide mobile profile link for admin
            const mobileProfileLinks = document.querySelectorAll('a[href*="my_profile.html"]');
            mobileProfileLinks.forEach(link => {
                if (link.closest('#mobile-menu')) {
                    link.style.display = 'none';
                }
            });
            
            // Ensure auth buttons are hidden when logged in
            if (authButtonsNav) authButtonsNav.classList.add('hidden');
            if (mobileAuthButtonsNav) mobileAuthButtonsNav.classList.add('hidden');
            
            // Update profile links for admin (though profile menu is hidden)
            updateProfileLinks('admin');
        } else if (role === 'host') {
            const hostNav = document.getElementById('host-nav');
            const mobileHostNav = document.getElementById('mobile-host-nav');
            if (hostNav) hostNav.classList.remove('hidden');
            if (mobileHostNav) mobileHostNav.classList.remove('hidden');
            
            // Ensure auth buttons are hidden when logged in
            if (authButtonsNav) authButtonsNav.classList.add('hidden');
            if (mobileAuthButtonsNav) mobileAuthButtonsNav.classList.add('hidden');
            
            // Update profile links for host
            updateProfileLinks('host');
        } else if (role === 'guest') {
            const guestNav = document.getElementById('guest-nav');
            const mobileGuestNav = document.getElementById('mobile-guest-nav');
            if (guestNav) guestNav.classList.remove('hidden');
            if (mobileGuestNav) mobileGuestNav.classList.remove('hidden');
            
            // Ensure auth buttons are hidden when logged in
            if (authButtonsNav) authButtonsNav.classList.add('hidden');
            if (mobileAuthButtonsNav) mobileAuthButtonsNav.classList.add('hidden');
            
            // Update profile links for guest
            updateProfileLinks('guest');
        } else {
            // Default to guest
            const guestNav = document.getElementById('guest-nav');
            const mobileGuestNav = document.getElementById('mobile-guest-nav');
            if (guestNav) guestNav.classList.remove('hidden');
            if (mobileGuestNav) mobileGuestNav.classList.remove('hidden');
            
            // Ensure auth buttons are hidden when logged in
            if (authButtonsNav) authButtonsNav.classList.add('hidden');
            if (mobileAuthButtonsNav) mobileAuthButtonsNav.classList.add('hidden');
            
            updateProfileLinks('guest');
        }
    } catch (error) {
        console.error('Error parsing user data:', error);
        // User data parse failed, show guest navigation
        const guestNav = document.getElementById('guest-nav');
        const mobileGuestNav = document.getElementById('mobile-guest-nav');
        if (guestNav) guestNav.classList.remove('hidden');
        if (mobileGuestNav) mobileGuestNav.classList.remove('hidden');
        
        updateProfileLinks('guest');
    }
}

// Function to update admin navigation links based on current page location
function updateAdminNavLinks() {
    const currentPath = window.location.pathname;
    
    // Determine the base path for admin links
    let adminBasePath = '';
    if (currentPath.includes('/admin/')) {
        // Already in admin directory, use relative paths (empty prefix)
        adminBasePath = '';
    } else if (currentPath.includes('/host/') || currentPath.includes('/guest/')) {
        // In host or guest directory, need to go up one level
        adminBasePath = '../admin/';
    } else {
        // In public root, go to admin directory
        adminBasePath = 'admin/';
    }
    
    // Map of admin link IDs to their corresponding pages
    const adminLinkMap = {
        'nav-admin-dashboard': 'dashboard.html',
        'nav-admin-users': 'users.html',
        'nav-admin-events': 'events.html',
        'nav-admin-attendees': 'attendees.html',
        'mobile-nav-admin-dashboard': 'dashboard.html',
        'mobile-nav-admin-users': 'users.html',
        'mobile-nav-admin-events': 'events.html',
        'mobile-nav-admin-attendees': 'attendees.html'
    };
    
    // Update all admin navigation links
    Object.keys(adminLinkMap).forEach(linkId => {
        const link = document.getElementById(linkId);
        if (link && link.getAttribute('href') !== '#') {
            const page = adminLinkMap[linkId];
            const newHref = adminBasePath + page;
            link.setAttribute('href', newHref);
        }
    });
}

// Function to update profile links based on role
function updateProfileLinks(role) {
    const currentPath = window.location.pathname;
    let basePath = '';
    
    // Determine base path based on current location
    if (currentPath.includes('/admin/')) {
        basePath = '';
    } else if (currentPath.includes('/host/')) {
        basePath = '';
    } else if (currentPath.includes('/guest/')) {
        basePath = '';
    } else {
        // In public root (index.html), need to add role prefix
        basePath = role + '/';
    }
    
    // Update profile dropdown links
    const profileDropdownLinks = document.querySelectorAll('#profile-dropdown a[href*="my_profile.html"], #profile-dropdown a[href*="help_support.html"]');
    profileDropdownLinks.forEach(link => {
        const href = link.getAttribute('href');
        if (href && !href.startsWith('http') && !href.startsWith('../') && !href.startsWith('/')) {
            link.href = basePath + href;
        }
    });
    
    // Update mobile menu profile links
    const mobileProfileLinks = document.querySelectorAll('#mobile-menu a[href*="my_profile.html"], #mobile-menu a[href*="help_support.html"]');
    mobileProfileLinks.forEach(link => {
        if (role === 'admin') {
            // Hide profile links for admin in mobile menu
            link.style.display = 'none';
        } else {
            link.style.display = '';
            const href = link.getAttribute('href');
            if (href && !href.startsWith('http') && !href.startsWith('../') && !href.startsWith('/')) {
                link.href = basePath + href;
            }
        }
    });
    
    // Update host navigation links if needed
    if (role === 'host') {
        const hostLinks = document.querySelectorAll('#host-nav a, #mobile-host-nav a');
        hostLinks.forEach(link => {
            const href = link.getAttribute('href');
            if (href && !href.startsWith('http') && !href.startsWith('../') && !href.startsWith('/')) {
                if (currentPath.includes('/host/')) {
                    // Already in host directory, keep relative path
                    if (href.startsWith('host/')) {
                        link.href = href.replace('host/', ''); // Remove duplicate prefix
                    } else {
                        link.href = href; // Already correct
                    }
                } else {
                    // Not in host directory, add prefix only if not already present
                    if (!href.startsWith('host/')) {
                        link.href = 'host/' + href;
                    }
                }
            }
        });
    }
    
    // Update guest navigation links if needed
    if (role === 'guest') {
        const guestLinks = document.querySelectorAll('#guest-nav a, #mobile-guest-nav a');
        guestLinks.forEach(link => {
            const href = link.getAttribute('href');
            if (href && !href.startsWith('http') && !href.startsWith('../') && !href.startsWith('/')) {
                if (currentPath.includes('/guest/')) {
                    // Already in guest directory, keep relative path
                    if (href.startsWith('guest/')) {
                        link.href = href.replace('guest/', ''); // Remove duplicate prefix
                    } else {
                        link.href = href; // Already correct
                    }
                } else {
                    // Not in guest directory, add prefix only if not already present
                    if (!href.startsWith('guest/')) {
                        link.href = 'guest/' + href;
                    }
                }
            }
        });
    }
}
function handleLogout(event) {
    event.preventDefault();
    localStorage.removeItem('token');
    localStorage.removeItem('user');
    
    // Determine correct login path based on current location
    const currentPath = window.location.pathname;
    let loginPath = '../login.html';
    
    if (currentPath.includes('/admin/') || currentPath.includes('/host/') || currentPath.includes('/guest/')) {
        loginPath = '../login.html';
    } else if (currentPath.endsWith('index.html') || currentPath.endsWith('/') || currentPath.includes('/public/')) {
        loginPath = 'login.html';
    } else {
        loginPath = '../login.html';
    }
    
    window.location.href = loginPath;
}

// Logout functionality
document.addEventListener('DOMContentLoaded', function () {
    const logoutLink = document.getElementById('logout-link');
    if (logoutLink) {
        logoutLink.addEventListener('click', handleLogout);
    }
});
