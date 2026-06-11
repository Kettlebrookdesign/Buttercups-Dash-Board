# Buttercups Staff Dashboard - Setup Instructions

## 1. Build the Frontend
1. Open a terminal in the frontend project root for the plugin (the folder containing `package.json`).
2. Run `npm install`
3. Run `npm run build`
4. Confirm the compiled assets are written to the plugin `build/` folder

## 2. Prepare the Plugin Zip
1. Make sure the plugin folder includes:
   - `buttercups-dashboard.php`
   - `includes/`
   - `build/`
2. Do not include unnecessary development files such as `node_modules`
3. Zip the `buttercups-dashboard` folder

## 3. Install in WordPress
1. Go to **WordPress Admin > Plugins > Add New > Upload Plugin**
2. Upload the zip
3. Activate the plugin

## 4. Access
- A new menu item such as **Booking Dash** should appear in the WordPress admin sidebar
- Only users with the `view_booking_dashboard` capability should be able to access it
- Confirm that this capability is added to the intended roles on activation

## 5. Live Testing Checklist
- [ ] Plugin activates without errors
- [ ] Booking Dash menu appears for allowed roles
- [ ] Booking Dash is hidden for disallowed roles
- [ ] REST API returns 403 for users without permission
- [ ] Today’s bookings match the Bookly calendar
- [ ] Attendee totals match Bookly group bookings correctly
- [ ] Slot detail guest names and references match Bookly
- [ ] Partial search works for both customer name and booking reference
- [ ] Tomorrow / Weekend filters load the correct results
- [ ] iPad landscape layout is readable and usable
- [ ] Plugin fails gracefully if Bookly is inactive or required tables are missing
