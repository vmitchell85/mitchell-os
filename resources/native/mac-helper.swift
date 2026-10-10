// Does the things only this Mac can do and prints JSON: reads calendars and
// events from macOS Calendar (EventKit), so the app can use the accounts already
// synced to this Mac such as Google, and asks Apple Maps for drive times.
// Read-only. `php artisan mac-helper:install` builds it into a small background
// app, launched with `open`, so macOS asks for access on its behalf.
//
//   mac-helper calendars
//   mac-helper events <from ISO 8601> <to ISO 8601> <calendar id>[,<calendar id>...]
//   mac-helper drive <from address> <to address> <arrive-by ISO 8601>

import CoreLocation
import EventKit
import Foundation
import MapKit

func fail(_ message: String, code: Int32 = 1) -> Never {
    FileHandle.standardError.write(message.data(using: .utf8)!)
    exit(code)
}

func printJSON(_ value: Any) {
    let data = try! JSONSerialization.data(withJSONObject: value, options: [.sortedKeys])
    FileHandle.standardOutput.write(data)
}

func hex(_ color: CGColor?) -> String? {
    guard let rgb = color?.converted(to: CGColorSpace(name: CGColorSpace.sRGB)!, intent: .defaultIntent, options: nil),
          let parts = rgb.components, parts.count >= 3 else { return nil }
    return String(format: "#%02X%02X%02X", Int(parts[0] * 255), Int(parts[1] * 255), Int(parts[2] * 255))
}

// Pumps the run loop until the work finishes, because the geocoder and Maps call
// back on the main thread, so a semaphore here would deadlock.
func waitUntil(_ seconds: Double, _ done: () -> Bool) {
    let deadline = Date().addingTimeInterval(seconds)
    while !done() && Date() < deadline {
        RunLoop.current.run(mode: .default, before: Date().addingTimeInterval(0.05))
    }
}

func mapItem(for address: String) -> MKMapItem {
    var found: CLPlacemark?
    var finished = false
    CLGeocoder().geocodeAddressString(address) { placemarks, _ in
        found = placemarks?.first
        finished = true
    }
    waitUntil(20) { finished }
    guard let found = found, let location = found.location else {
        fail("Couldn't find the address \"\(address)\".")
    }
    return MKMapItem(location: location, address: nil)
}

func requestCalendarAccess() -> EKEventStore {
    let store = EKEventStore()

    switch EKEventStore.authorizationStatus(for: .event) {
    case .fullAccess:
        break
    case .notDetermined:
        let semaphore = DispatchSemaphore(value: 0)
        var granted = false
        store.requestFullAccessToEvents { allowed, _ in
            granted = allowed
            semaphore.signal()
        }
        semaphore.wait()
        if !granted {
            fail("Calendar access was not allowed.", code: 2)
        }
    default:
        fail("Calendar access is turned off. Allow it in System Settings > Privacy & Security > Calendars.", code: 2)
    }
    return store
}

let arguments = CommandLine.arguments
let local = ISO8601DateFormatter()
local.timeZone = TimeZone.current
local.formatOptions = [.withInternetDateTime]

let day = DateFormatter()
day.dateFormat = "yyyy-MM-dd"
day.timeZone = TimeZone.current

switch arguments.count > 1 ? arguments[1] : "" {
case "calendars":
    let store = requestCalendarAccess()
    printJSON(store.calendars(for: .event).map { calendar in
        [
            "id": calendar.calendarIdentifier,
            "title": calendar.title,
            "account": calendar.source?.title ?? "",
            "color": hex(calendar.cgColor) as Any,
        ]
    })

case "events":
    let store = requestCalendarAccess()
    guard arguments.count == 5,
          let from = ISO8601DateFormatter().date(from: arguments[2]),
          let to = ISO8601DateFormatter().date(from: arguments[3]) else {
        fail("Usage: mac-helper events <from> <to> <calendar ids>")
    }
    let ids = Set(arguments[4].split(separator: ",").map(String.init))
    let calendars = store.calendars(for: .event).filter { ids.contains($0.calendarIdentifier) }
    if calendars.isEmpty {
        printJSON([])
        exit(0)
    }
    let events = store.events(matching: store.predicateForEvents(withStart: from, end: to, calendars: calendars))
    printJSON(events.sorted { $0.startDate < $1.startDate }.map { event in
        [
            "calendar_id": event.calendar.calendarIdentifier,
            "title": event.title ?? "",
            "all_day": event.isAllDay,
            // All-day events end at the start of the day after, so report their last day.
            "start": event.isAllDay ? day.string(from: event.startDate) : local.string(from: event.startDate),
            "end": event.isAllDay ? day.string(from: event.endDate.addingTimeInterval(-1)) : local.string(from: event.endDate),
            "location": event.location as Any,
        ]
    })

case "drive":
    guard arguments.count == 5, let arriveBy = ISO8601DateFormatter().date(from: arguments[4]) else {
        fail("Usage: mac-helper drive <from address> <to address> <arrive-by>")
    }
    let request = MKDirections.Request()
    request.source = mapItem(for: arguments[2])
    request.destination = mapItem(for: arguments[3])
    request.transportType = .automobile
    request.arrivalDate = arriveBy

    var estimate: MKDirections.ETAResponse?
    var failure: Error?
    var finished = false
    MKDirections(request: request).calculateETA { response, error in
        estimate = response
        failure = error
        finished = true
    }
    waitUntil(20) { finished }
    guard let estimate = estimate else {
        fail(failure?.localizedDescription ?? "Apple Maps didn't answer.")
    }
    printJSON([
        "minutes": Int((estimate.expectedTravelTime / 60).rounded(.up)),
        "miles": (estimate.distance / 1609.344 * 10).rounded() / 10,
        // Maps predicts traffic for the arrival time we set.
        "traffic_aware": true,
    ])

default:
    fail("Usage: mac-helper calendars | events <from> <to> <calendar ids> | drive <from> <to> <arrive-by>")
}
