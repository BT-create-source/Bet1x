const axios = require('axios');
const key = 'b26690cf-eff8-40ac-a4cb-ddccae915ac7';
const matchId = 'e59065db-2007-4c34-bbf9-11a54fd2dddd';
axios.get(`https://api.cricapi.com/v1/match_squad?apikey=${key}&id=${matchId}`)
  .then(res => {
    console.log("Success! Match Squad preview:", JSON.stringify(res.data, null, 2).slice(0, 1500));
  })
  .catch(err => {
    console.error("API error:", err.message);
  });
