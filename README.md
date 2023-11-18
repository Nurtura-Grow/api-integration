# Webhook-antares

How to run webhook (local deployment) from Antares Device.

1. `npm run serve` to serve the laravel project in local
2. `npm run ngrok` to run ngrok, copy the "forwarding" link (https:// ........ .ngrok-free.app)
![Ngrok](https://github.com/Nurtura-Grow/webhook-antares/blob/main/documentation/1-ngrok.png?raw=true)

3. Open antares [website](https://beta-console.antares.id/)
4. Login to antares website, open `Device Management`, choose your LoRa device.
![Device management](https://github.com/Nurtura-Grow/webhook-antares/blob/main/documentation/2-device-management.png?raw=true)
5. Follow these [instructions for creating subscriber](https://docs.antares.id/api-or-http/subscriber#create-subscriber-of-device). NB: for `nu` use your ngrok link with the neccessary endpoint (from number 2, save the subscriber id for deleting the subscriber)
![Postman Subscriber](https://github.com/Nurtura-Grow/webhook-antares/blob/main/documentation/3-postman-subscriber.png?raw=true)
6. Wait for data to come or make your own data using postman (just for testing) like [this](https://docs.antares.id/api-or-http/data-of-device#store-data-of-a-particular-device)
7. If it's done, you can delete the subscriber [like this](https://docs.antares.id/api-or-http/subscriber#delete-subscriber-of-device) (dont forget to update the subscriber id from number 5)
